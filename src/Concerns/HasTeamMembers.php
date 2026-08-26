<?php

namespace Packstub\Teams\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Packstub\Teams\Facades\Teams;
use Packstub\Teams\Models\Membership;
use Packstub\Teams\Models\TeamInvitation;

/**
 * For the team (tenant) model. Optional: every helper delegates to the
 * Teams service, which can also build the relations from config for models
 * you don't control (e.g. packstub/filament-tenancy's Tenant).
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
        return Teams::ownersOf($this);
    }

    public function hasMember(Model $user): bool
    {
        return Teams::isMember($this, $user);
    }

    public function roleOf(Model $user): ?string
    {
        return Teams::roleOf($this, $user);
    }

    public function isOwnedBy(Model $user): bool
    {
        return Teams::roleOf($this, $user) === Teams::ownerRole();
    }

    public function seatsUsed(): int
    {
        return Teams::seatsUsed($this);
    }

    public function seatLimit(): ?int
    {
        return Teams::seatLimit($this);
    }

    public function hasAvailableSeats(int $needed = 1): bool
    {
        return Teams::hasAvailableSeats($this, $needed);
    }
}
