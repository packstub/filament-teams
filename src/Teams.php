<?php

namespace Packstub\Teams;

use Closure;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
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
use Packstub\Teams\Exceptions\InvalidEmail;
use Packstub\Teams\Exceptions\InvalidInvitation;
use Packstub\Teams\Exceptions\InvalidRole;
use Packstub\Teams\Exceptions\LastOwnerException;
use Packstub\Teams\Exceptions\NotAMember;
use Packstub\Teams\Exceptions\ResendTooSoon;
use Packstub\Teams\Exceptions\SeatLimitReached;
use Packstub\Teams\Exceptions\TeamsException;
use Packstub\Teams\Mail\TeamInvitationMail;
use Packstub\Teams\Models\Membership;
use Packstub\Teams\Models\TeamInvitation;
use Packstub\Teams\Notifications\TeamInvitationNotification;
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

    protected ?Closure $personalTeamUsing = null;

    public const SESSION_INVITATION = 'packstub-teams.invitation';

    /** Set when a listener accepted the session invitation, so the accept route can still greet. */
    public const SESSION_JOINED = 'packstub-teams.joined';

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

    /**
     * fn (Model $user): Model — builds (and saves) a new user's personal team.
     */
    public function personalTeamUsing(?Closure $callback): static
    {
        $this->personalTeamUsing = $callback;

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

    public function roleDescription(Model $team, string $role): ?string
    {
        return $this->roleProvider()->describe($team, $role);
    }

    /**
     * Role → description map for pickers.
     *
     * @return array<string, string>
     */
    public function roleDescriptions(Model $team): array
    {
        return collect($this->roles($team))
            ->map(fn (string $label, string $role): ?string => $this->roleDescription($team, $role))
            ->filter()
            ->all();
    }

    /**
     * Does the user hold $permission in $team? Owners hold everything;
     * other members are answered by the role provider (config permissions,
     * or Spatie / Shield).
     */
    public function userCan(Model $user, Model $team, string $permission): bool
    {
        $role = $this->roleOf($team, $user);

        if ($role === null) {
            return false;
        }

        if ($role === $this->ownerRole()) {
            return true;
        }

        return $this->roleProvider()->can($user, $team, $role, $permission);
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
    // Personal teams
    // ------------------------------------------------------------------

    public function createsPersonalTeams(): bool
    {
        return (bool) config('packstub-teams.personal_teams.enabled', false) && $this->personalTeamUsing !== null;
    }

    /**
     * Create the user's personal team through the configured factory and
     * make them its owner. Returns null when personal teams are off.
     */
    public function createPersonalTeam(Model $user): ?Model
    {
        if (! $this->createsPersonalTeams()) {
            return null;
        }

        /** @var Model $team */
        $team = ($this->personalTeamUsing)($user);

        if (! $this->isMember($team, $user)) {
            $this->addMember($team, $user, $this->ownerRole());
        }

        return $team;
    }

    public function personalTeamName(Model $user): string
    {
        return str_replace(':name', (string) ($user->getAttribute('name') ?? 'My'), (string) config('packstub-teams.personal_teams.name', ":name's Team"));
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
            // Links hold no seat until someone uses them.
            $seats += $this->invitationsOf($team)->pending()->emails()->count();
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

        if (! $this->isValidEmail($email)) {
            throw InvalidEmail::make();
        }

        $this->assertRole($team, $role, allowOwner: false);

        $members = $this->membersOf($team);

        if ($this->whereEmail($members->getQuery(), $members->getRelated()->qualifyColumn('email'), $email)->exists()) {
            throw AlreadyAMember::make();
        }

        /** @var TeamInvitation|null $existing */
        $existing = $this->invitationsOf($team)->where('email', $email)->first();

        if ($existing && $existing->accepted_at !== null) {
            $existing->delete();
            $existing = null;
        }

        // Re-inviting a pending address re-sends it, so the resend cooldown
        // applies here too; otherwise the Invite action mails without limit.
        if ($send && $existing?->isPending() && ($at = $existing->canResendAt())) {
            throw ResendTooSoon::until($at);
        }

        // A still-pending invitation already holds its seat; only a brand
        // new (or expired, hence uncounted) one needs a free seat.
        if (($existing === null || ! $existing->isPending()) && ! $this->hasAvailableSeats($team)) {
            throw SeatLimitReached::make();
        }

        // Updating a pending invitation without mailing keeps its token, so
        // the link already e-mailed keeps working.
        $keepToken = ! $send && $existing !== null && $existing->isPending();

        $invitation = $existing ?? new TeamInvitation;
        $invitation->fill([
            'email' => $email,
            'role' => $role,
            'invited_by' => $inviter?->getKey(),
            'expires_at' => $this->invitationExpiry(),
            'accepted_at' => null,
            'max_uses' => 1,
            'uses' => 0,
        ]);
        $invitation->team()->associate($team);
        if (! $keepToken) {
            $invitation->token = TeamInvitation::generateToken();
        }

        $invitation->save();

        MemberInvited::dispatch($invitation);

        if ($send) {
            $this->sendInvitation($invitation);
        }

        return $invitation;
    }

    /**
     * Invite several addresses at once. Addresses that fail a rule are
     * reported instead of aborting the batch. A string (or an item) holding
     * several addresses separated by commas, semicolons or spaces is split.
     *
     * @param  iterable<int, string>|string  $emails
     * @return array{invitations: array<int, TeamInvitation>, errors: array<string, string>}
     */
    public function inviteMany(Model $team, iterable|string $emails, ?string $role = null, ?Model $inviter = null, bool $send = true): array
    {
        $emails = collect(is_string($emails) ? [$emails] : $emails)
            ->flatMap(fn (mixed $e): array => is_scalar($e) ? (preg_split('/[\s,;]+/', (string) $e, -1, PREG_SPLIT_NO_EMPTY) ?: []) : [])
            ->map(fn (string $e): string => Str::lower($e))
            ->unique()
            ->values();
        $max = (int) config('packstub-teams.invitations.max_batch', 10);

        if ($max > 0 && $emails->count() > $max) {
            throw new TeamsException(__('packstub-teams::teams.errors.too_many_invites', ['max' => $max]));
        }

        $invitations = [];
        $errors = [];

        foreach ($emails as $email) {
            try {
                $invitations[] = $this->invite($team, $email, $role, $inviter, $send);
            } catch (TeamsException $exception) {
                $errors[$email] = $exception->getMessage();
            }
        }

        return ['invitations' => $invitations, 'errors' => $errors];
    }

    /**
     * The address check the Invite action and inviteMany() share.
     */
    public function isValidEmail(string $email): bool
    {
        return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * A shareable link anyone signed in may use (until it expires or its
     * uses run out). Links never count toward seats until they are used.
     */
    public function createInviteLink(Model $team, ?string $role = null, ?int $maxUses = null, ?Carbon $expiresAt = null, ?Model $inviter = null): TeamInvitation
    {
        $role ??= $this->defaultRoleFor($team);

        $this->assertRole($team, $role, allowOwner: false);

        $invitation = new TeamInvitation([
            'email' => null,
            'role' => $role,
            'invited_by' => $inviter?->getKey(),
            'expires_at' => $expiresAt ?? $this->invitationExpiry(),
            'max_uses' => $maxUses,
            'uses' => 0,
        ]);
        $invitation->team()->associate($team);
        $invitation->save();

        MemberInvited::dispatch($invitation);

        return $invitation;
    }

    public function resend(TeamInvitation $invitation): TeamInvitation
    {
        if ($invitation->accepted_at !== null || $invitation->isLink()) {
            throw InvalidInvitation::make();
        }

        if ($at = $invitation->canResendAt()) {
            throw ResendTooSoon::until($at);
        }

        // An expired invitation stopped holding a seat; reviving it takes one.
        if (! $invitation->isPending() && ! $this->hasAvailableSeats($invitation->team)) {
            throw SeatLimitReached::make();
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

        if (! $invitation->isLink()
            && config('packstub-teams.invitations.require_email_match', true)
            && Str::lower((string) $user->getAttribute('email')) !== $invitation->email) {
            throw InvalidInvitation::make();
        }

        /** @var Model $team */
        $team = $invitation->team;

        if ($this->isMember($team, $user)) {
            if (! $invitation->isLink()) {
                $invitation->delete();
            }

            throw AlreadyAMember::make();
        }

        $before = $invitation->getAttributes();

        try {
            DB::transaction(function () use ($invitation, $team, $user): void {
                $invitation->uses++;

                // An e-mail invitation is single use and counts as a seat while
                // pending, so close it before the membership takes that seat.
                if (! $invitation->isLink() || $invitation->isExhausted()) {
                    $invitation->accepted_at = now();
                }

                $invitation->save();

                $this->addMember($team, $user, $invitation->role);
            });
        } catch (\Throwable $exception) {
            // The rollback restored the row; put the model back the same way
            // without a query (the row may be gone if it was revoked meanwhile).
            $invitation->setRawAttributes($before, true);

            throw $exception;
        }

        InvitationAccepted::dispatch($invitation, $user);
    }

    /**
     * Accept every pending e-mail invitation addressed to the user. Returns
     * the teams joined; rule violations (seats, already a member) are
     * skipped silently — the user can still follow the link later.
     *
     * @return array<int, Model>
     */
    public function acceptAllFor(Model $user): array
    {
        $email = Str::lower((string) $user->getAttribute('email'));
        $joined = [];

        if ($email === '') {
            return $joined;
        }

        foreach (TeamInvitation::query()->pending()->where('email', $email)->get() as $invitation) {
            try {
                $this->accept($invitation, $user);
                $joined[] = $invitation->team;
            } catch (TeamsException) {
                // Left pending for the link.
            }
        }

        return $joined;
    }

    /**
     * The invitation whose link brought the current visitor here, if any.
     */
    public function pendingInvitationFromSession(): ?TeamInvitation
    {
        $token = session()->get(static::SESSION_INVITATION.'.token');

        return is_string($token) ? $this->findPendingInvitation($token) : null;
    }

    /**
     * Delete expired invitations and accepted ones older than the
     * configured retention. Returns the number of rows removed.
     */
    public function pruneInvitations(): int
    {
        $days = (int) config('packstub-teams.invitations.prune_accepted_after_days', 30);

        $expired = TeamInvitation::query()->whereNull('accepted_at')->whereNotNull('expires_at')->where('expires_at', '<', now())->delete();
        $accepted = TeamInvitation::query()->whereNotNull('accepted_at')->where('accepted_at', '<', now()->subDays($days))->delete();

        return $expired + $accepted;
    }

    public function findPendingInvitation(string $token): ?TeamInvitation
    {
        return TeamInvitation::query()->pending()->where('token', $token)->first();
    }

    public function sendInvitation(TeamInvitation $invitation): void
    {
        if ($invitation->isLink()) {
            return;
        }

        $mailable = new TeamInvitationMail($invitation);
        $pending = Mail::to($invitation->email);

        config('packstub-teams.invitations.mail.queue', false)
            ? $pending->queue($mailable)
            : $pending->send($mailable);

        $invitation->forceFill(['last_sent_at' => now()])->saveQuietly();

        $this->notifyExistingUser($invitation);
    }

    /**
     * An in-app (database) notification for invitees who already have an
     * account, so the invite shows up even if the e-mail is missed.
     */
    protected function notifyExistingUser(TeamInvitation $invitation): void
    {
        if (! config('packstub-teams.invitations.notify_existing_users', true)) {
            return;
        }

        $model = config('auth.providers.users.model');
        $query = $model::query();
        $user = $this->whereEmail($query, $query->getModel()->qualifyColumn('email'), (string) $invitation->email)->first();

        if (! $user || ! method_exists($user, 'notify')) {
            return;
        }

        try {
            $user->notify(new TeamInvitationNotification($invitation));
        } catch (\Throwable $exception) {
            report($exception);
        }
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

    /**
     * Match an e-mail column regardless of case: invitations store
     * addresses lower-cased, user tables often keep what was typed.
     * MySQL, MariaDB and SQL Server compare case-insensitively under their
     * default collations, so a plain (indexed) where does; SQLite and
     * PostgreSQL compare case-sensitively and get lower() on the column.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected function whereEmail(Builder $query, string $column, string $email): Builder
    {
        $email = Str::lower(trim($email));

        if (in_array($query->getQuery()->getConnection()->getDriverName(), ['mysql', 'mariadb', 'sqlsrv'], true)) {
            return $query->where($column, $email);
        }

        $grammar = $query->getQuery()->getGrammar();

        return $query->whereRaw('lower('.$grammar->wrap($column).') = ?', [$email]);
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
