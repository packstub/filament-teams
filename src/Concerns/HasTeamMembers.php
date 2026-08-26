<?php

namespace Packstub\Teams\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Packstub\Teams\Facades\Teams;
use Packstub\Teams\Models\Membership;
use Packstub\Teams\Models\TeamInvitation;

/**
 * For the team (tenant) model.
 */
trait HasTeamMembers
{
    public function members(): BelongsToMany
    {
        $config = config('packstub-teams.membership');

        return $this->belongsToMany(
            config('auth.providers.users.model'),
            $config['table'],
            $config['team_foreign_key'],
            $config['user_foreign_key'],
        )
            ->using(Membership::class)
            ->withPivot($config['role_column'])
            ->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class, 'team_id');
    }

    public function pendingInvitations(): HasMany
    {
        return $this->invitations()->pending();
    }

    public function owners(): BelongsToMany
    {
        return $this->members()->wherePivot(config('packstub-teams.membership.role_column'), Teams::ownerRole());
    }

    public function hasMember(Model $user): bool
    {
        return $this->members()->whereKey($user->getKey())->exists();
    }

    public function roleOf(Model $user): ?string
    {
        $role = $this->members()->whereKey($user->getKey())
            ->first()?->pivot?->getAttribute(config('packstub-teams.membership.role_column'));

        return $role === null ? null : (string) $role;
    }

    public function isOwnedBy(Model $user): bool
    {
        return $this->roleOf($user) === Teams::ownerRole();
    }

    public function seatsUsed(): int
    {
        $seats = $this->members()->count();

        if (config('packstub-teams.invitations.count_pending_toward_seats', true)) {
            $seats += $this->pendingInvitations()->count();
        }

        return $seats;
    }

    public function seatLimit(): ?int
    {
        return Teams::seatLimit($this);
    }

    public function hasAvailableSeats(int $needed = 1): bool
    {
        $limit = $this->seatLimit();

        return $limit === null || $this->seatsUsed() + $needed <= $limit;
    }
}
