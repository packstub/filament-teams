<?php

namespace Packstub\Teams;

use Closure;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Packstub\Teams\Events\InvitationAccepted;
use Packstub\Teams\Events\MemberAdded;
use Packstub\Teams\Events\MemberInvited;
use Packstub\Teams\Events\MemberRemoved;
use Packstub\Teams\Events\MemberRoleChanged;
use Packstub\Teams\Events\OwnershipTransferred;
use Packstub\Teams\Exceptions\AlreadyAMember;
use Packstub\Teams\Exceptions\InvalidInvitation;
use Packstub\Teams\Exceptions\InvalidRole;
use Packstub\Teams\Exceptions\LastOwnerException;
use Packstub\Teams\Exceptions\NotAMember;
use Packstub\Teams\Exceptions\SeatLimitReached;
use Packstub\Teams\Mail\TeamInvitationMail;
use Packstub\Teams\Models\Membership;
use Packstub\Teams\Models\TeamInvitation;
use Packstub\Teams\Roles\RoleProvider;
use Packstub\Teams\Roles\SimpleRoleProvider;
use Packstub\Teams\Roles\SpatieRoleProvider;

/**
 * Membership rules in one place: invitations, seats, roles and ownership.
 * Filament pages, events and the accept route all go through here.
 */
class Teams
{
    protected ?RoleProvider $roleProvider = null;

    protected ?Closure $seatLimitUsing = null;

    protected ?Closure $canManageUsing = null;

    protected ?Closure $canInviteUsing = null;

    public const SESSION_INVITATION = 'packstub-teams.invitation';

    // ------------------------------------------------------------------
    // Configuration hooks (set by TeamsPlugin or the host app)
    // ------------------------------------------------------------------

    public function useRoleProvider(RoleProvider|string|null $provider): static
    {
        $this->roleProvider = is_string($provider) ? app($provider) : $provider;

        return $this;
    }

    public function seatLimitUsing(?Closure $callback): static
    {
        $this->seatLimitUsing = $callback;

        return $this;
    }

    public function canManageUsing(?Closure $callback): static
    {
        $this->canManageUsing = $callback;

        return $this;
    }

    public function canInviteUsing(?Closure $callback): static
    {
        $this->canInviteUsing = $callback;

        return $this;
    }

    // ------------------------------------------------------------------
    // Roles
    // ------------------------------------------------------------------

    public function roleProvider(): RoleProvider
    {
        if ($this->roleProvider) {
            return $this->roleProvider;
        }

        $configured = config('packstub-teams.roles.provider');

        return $this->roleProvider = match (true) {
            $configured === 'simple' => app(SimpleRoleProvider::class),
            $configured === 'spatie' => app(SpatieRoleProvider::class),
            is_string($configured) && $configured !== '' => app($configured),
            SpatieRoleProvider::isAvailable() => app(SpatieRoleProvider::class),
            default => app(SimpleRoleProvider::class),
        };
    }

    public function usesSpatieRoles(): bool
    {
        return $this->roleProvider() instanceof SpatieRoleProvider;
    }

    /**
     * @return array<string, string>
     */
    public function roles(Model $team): array
    {
        return $this->roleProvider()->roles($team);
    }

    /**
     * Roles that may be handed out through the UI (everything but owner —
     * ownership moves through transferOwnership()).
     *
     * @return array<string, string>
     */
    public function assignableRoles(Model $team): array
    {
        $roles = $this->roles($team);
        unset($roles[$this->ownerRole()]);

        return $roles;
    }

    public function roleLabel(Model $team, string $role): string
    {
        return $this->roles($team)[$role] ?? Str::headline($role);
    }

    public function ownerRole(): string
    {
        return (string) config('packstub-teams.roles.owner', 'owner');
    }

    public function defaultRole(): string
    {
        return (string) config('packstub-teams.roles.default', 'member');
    }

    /**
     * The role new invites get in this team: the configured default when the
     * provider offers it, otherwise the first assignable role.
     */
    public function defaultRoleFor(Model $team): string
    {
        $assignable = $this->assignableRoles($team);

        if (array_key_exists($this->defaultRole(), $assignable)) {
            return $this->defaultRole();
        }

        return (string) (array_key_first($assignable) ?? $this->defaultRole());
    }

    /**
     * @return array<int, string>
     */
    public function managerRoles(): array
    {
        return array_values(array_unique([$this->ownerRole(), ...(array) config('packstub-teams.roles.managers', [])]));
    }

    public function canManage(Model $user, Model $team): bool
    {
        if ($this->canManageUsing) {
            return (bool) ($this->canManageUsing)($user, $team);
        }

        return in_array($this->roleOf($team, $user), $this->managerRoles(), true);
    }

    public function canInvite(Model $user, Model $team): bool
    {
        if ($this->canInviteUsing) {
            return (bool) ($this->canInviteUsing)($user, $team);
        }

        return $this->canManage($user, $team);
    }

    // ------------------------------------------------------------------
    // Relations & lookups (work with or without the traits)
    // ------------------------------------------------------------------

    /**
     * The team's member relation: the model's own (`members()` or the
     * configured name) when present, otherwise built from config.
     */
    public function membersOf(Model $team): BelongsToMany
    {
        $config = config('packstub-teams.membership');
        $name = $config['team_relationship'] ?? 'members';

        if (method_exists($team, $name)) {
            return $team->{$name}();
        }

        return $team->belongsToMany(
            config('auth.providers.users.model'),
            $config['table'],
            $config['team_foreign_key'],
            $config['user_foreign_key'],
        )
            ->using(Membership::class)
            ->withPivot($config['role_column'])
            ->withTimestamps();
    }

    /**
     * The user's team relation (inverse of membersOf()).
     */
    public function teamsOf(Model $user): BelongsToMany
    {
        $config = config('packstub-teams.membership');
        $name = $this->userTeamsRelationName();

        if (method_exists($user, $name)) {
            return $user->{$name}();
        }

        return $user->belongsToMany(
            config('packstub-teams.team_model'),
            $config['table'],
            $config['user_foreign_key'],
            $config['team_foreign_key'],
        )
            ->using(Membership::class)
            ->withPivot($config['role_column'])
            ->withTimestamps();
    }

    public function userTeamsRelationName(): string
    {
        return (string) (config('packstub-teams.membership.user_relationship') ?? 'joinedTeams');
    }

    public function invitationsOf(Model $team): HasMany
    {
        if (method_exists($team, 'invitations')) {
            return $team->invitations();
        }

        return $team->hasMany(TeamInvitation::class, 'team_id');
    }

    public function ownersOf(Model $team): BelongsToMany
    {
        return $this->membersOf($team)->wherePivot($this->roleColumn(), $this->ownerRole());
    }

    public function isMember(Model $team, Model $user): bool
    {
        return $this->membersOf($team)->whereKey($user->getKey())->exists();
    }

    public function roleOf(Model $team, Model $user): ?string
    {
        $role = $this->membersOf($team)->whereKey($user->getKey())->first()?->pivot?->getAttribute($this->roleColumn());

        return $role === null ? null : (string) $role;
    }

    public function seatsUsed(Model $team): int
    {
        $seats = $this->membersOf($team)->count();

        if (config('packstub-teams.invitations.count_pending_toward_seats', true)) {
            $seats += $this->invitationsOf($team)->pending()->count();
        }

        return $seats;
    }

    public function hasAvailableSeats(Model $team, int $needed = 1): bool
    {
        $limit = $this->seatLimit($team);

        return $limit === null || $this->seatsUsed($team) + $needed <= $limit;
    }

    // ------------------------------------------------------------------
    // Seats
    // ------------------------------------------------------------------

    public function seatLimit(Model $team): ?int
    {
        if ($this->seatLimitUsing) {
            $limit = ($this->seatLimitUsing)($team);

            return $limit === null ? null : (int) $limit;
        }

        $limit = config('packstub-teams.seat_limit');

        return $limit === null || $limit === '' ? null : (int) $limit;
    }

    // ------------------------------------------------------------------
    // Membership
    // ------------------------------------------------------------------

    public function addMember(Model $team, Model $user, ?string $role = null): void
    {
        $role ??= $this->defaultRoleFor($team);

        $this->assertRole($team, $role, allowOwner: true);

        if ($this->isMember($team, $user)) {
            throw AlreadyAMember::make();
        }

        if (! $this->hasAvailableSeats($team)) {
            throw SeatLimitReached::make();
        }

        DB::transaction(function () use ($team, $user, $role): void {
            $this->membersOf($team)->attach($user->getKey(), [$this->roleColumn() => $role]);
            $this->roleProvider()->assigned($user, $team, $role);
        });

        $this->forgetMembers($team);

        MemberAdded::dispatch($team, $user, $role);
    }

    public function changeRole(Model $team, Model $user, string $role): void
    {
        $this->assertRole($team, $role, allowOwner: false);

        $current = $this->roleOf($team, $user) ?? throw NotAMember::make();

        if ($current === $role) {
            return;
        }

        if ($current === $this->ownerRole()) {
            $this->assertNotLastOwner($team);
        }

        DB::transaction(function () use ($team, $user, $role): void {
            $this->membersOf($team)->updateExistingPivot($user->getKey(), [$this->roleColumn() => $role]);
            $this->roleProvider()->assigned($user, $team, $role);
        });

        $this->forgetMembers($team);

        MemberRoleChanged::dispatch($team, $user, $current, $role);
    }

    public function removeMember(Model $team, Model $user): void
    {
        $current = $this->roleOf($team, $user) ?? throw NotAMember::make();

        if ($current === $this->ownerRole()) {
            $this->assertNotLastOwner($team);
        }

        DB::transaction(function () use ($team, $user): void {
            $this->membersOf($team)->detach($user->getKey());
            $this->roleProvider()->removed($user, $team);
        });

        $this->forgetMembers($team);

        MemberRemoved::dispatch($team, $user);
    }

    public function transferOwnership(Model $team, Model $from, Model $to): void
    {
        if ($this->roleOf($team, $from) !== $this->ownerRole()) {
            throw NotAMember::make();
        }

        if (! $this->isMember($team, $to)) {
            throw NotAMember::make();
        }

        if ($from->is($to)) {
            return;
        }

        $demotedRole = $this->demotedOwnerRole($team);

        DB::transaction(function () use ($team, $from, $to, $demotedRole): void {
            $this->membersOf($team)->updateExistingPivot($to->getKey(), [$this->roleColumn() => $this->ownerRole()]);
            $this->roleProvider()->assigned($to, $team, $this->ownerRole());

            $this->membersOf($team)->updateExistingPivot($from->getKey(), [$this->roleColumn() => $demotedRole]);
            $this->roleProvider()->assigned($from, $team, $demotedRole);
        });

        $this->forgetMembers($team);

        OwnershipTransferred::dispatch($team, $from, $to);
    }

    // ------------------------------------------------------------------
    // Invitations
    // ------------------------------------------------------------------

    public function invite(Model $team, string $email, ?string $role = null, ?Model $inviter = null, bool $send = true): TeamInvitation
    {
        $role ??= $this->defaultRoleFor($team);
        $email = Str::lower(trim($email));

        $this->assertRole($team, $role, allowOwner: false);

        if ($this->membersOf($team)->where('email', $email)->exists()) {
            throw AlreadyAMember::make();
        }

        /** @var TeamInvitation|null $existing */
        $existing = $this->invitationsOf($team)->where('email', $email)->first();

        if ($existing && $existing->accepted_at !== null) {
            $existing->delete();
            $existing = null;
        }

        // A still-pending invitation already holds its seat; only a brand
        // new (or expired, hence uncounted) one needs a free seat.
        if (($existing === null || ! $existing->isPending()) && ! $this->hasAvailableSeats($team)) {
            throw SeatLimitReached::make();
        }

        $invitation = $existing ?? new TeamInvitation;
        $invitation->fill([
            'email' => $email,
            'role' => $role,
            'invited_by' => $inviter?->getKey(),
            'expires_at' => $this->invitationExpiry(),
            'accepted_at' => null,
        ]);
        $invitation->team()->associate($team);
        $invitation->token = TeamInvitation::generateToken();
        $invitation->save();

        MemberInvited::dispatch($invitation);

        if ($send) {
            $this->sendInvitation($invitation);
        }

        return $invitation;
    }

    public function resend(TeamInvitation $invitation): TeamInvitation
    {
        if ($invitation->accepted_at !== null) {
            throw InvalidInvitation::make();
        }

        $invitation->regenerateToken();
        $this->sendInvitation($invitation);

        return $invitation;
    }

    public function revoke(TeamInvitation $invitation): void
    {
        $invitation->delete();
    }

    public function accept(TeamInvitation $invitation, Model $user): void
    {
        if (! $invitation->isPending()) {
            throw InvalidInvitation::make();
        }

        if (config('packstub-teams.invitations.require_email_match', true)
            && Str::lower((string) $user->getAttribute('email')) !== $invitation->email) {
            throw InvalidInvitation::make();
        }

        /** @var Model $team */
        $team = $invitation->team;

        if ($this->isMember($team, $user)) {
            $invitation->delete();

            throw AlreadyAMember::make();
        }

        DB::transaction(function () use ($invitation, $team, $user): void {
            // The pending invitation counts as a seat when configured, so
            // free it before the membership takes that seat.
            $invitation->forceFill(['accepted_at' => now()])->save();

            $this->addMember($team, $user, $invitation->role);
        });

        InvitationAccepted::dispatch($invitation, $user);
    }

    public function findPendingInvitation(string $token): ?TeamInvitation
    {
        return TeamInvitation::query()->pending()->where('token', $token)->first();
    }

    public function sendInvitation(TeamInvitation $invitation): void
    {
        $mailable = new TeamInvitationMail($invitation);
        $pending = Mail::to($invitation->email);

        config('packstub-teams.invitations.mail.queue', false)
            ? $pending->queue($mailable)
            : $pending->send($mailable);
    }

    public function invitationExpiry(): ?Carbon
    {
        $days = config('packstub-teams.invitations.expire_after_days', 7);

        return $days ? now()->addDays((int) $days) : null;
    }

    public function acceptUrl(TeamInvitation $invitation, Panel|string|null $panel = null): string
    {
        $panel = match (true) {
            $panel instanceof Panel => $panel,
            is_string($panel) => Filament::getPanel($panel),
            default => Filament::getCurrentPanel() ?? Filament::getDefaultPanel(),
        };

        return $panel->route('teams.invitations.accept', ['token' => $invitation->token]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    protected function roleColumn(): string
    {
        return (string) config('packstub-teams.membership.role_column', 'role');
    }

    protected function assertRole(Model $team, string $role, bool $allowOwner): void
    {
        if ($role === $this->ownerRole()) {
            if (! $allowOwner) {
                throw InvalidRole::make();
            }

            return;
        }

        if (! array_key_exists($role, $this->roles($team))) {
            throw InvalidRole::make();
        }
    }

    protected function forgetMembers(Model $team): void
    {
        $team->unsetRelation(config('packstub-teams.membership.team_relationship', 'members'));
    }

    protected function assertNotLastOwner(Model $team): void
    {
        if ($this->ownersOf($team)->count() <= 1) {
            throw LastOwnerException::make();
        }
    }

    protected function demotedOwnerRole(Model $team): string
    {
        $assignable = $this->assignableRoles($team);

        foreach ((array) config('packstub-teams.roles.managers', []) as $role) {
            if (array_key_exists($role, $assignable)) {
                return $role;
            }
        }

        return array_key_exists($this->defaultRole(), $assignable)
            ? $this->defaultRole()
            : (string) array_key_first($assignable);
    }

    public function currentUser(): (Model&Authenticatable)|null
    {
        /** @var (Model&Authenticatable)|null $user */
        $user = Filament::auth()->user();

        return $user;
    }
}
