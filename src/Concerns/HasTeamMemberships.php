<?php

namespace Packstub\Teams\Concerns;

use Filament\Panel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Packstub\Teams\Facades\Teams;
use Packstub\Teams\Models\Membership;

/**
 * For the user model. Also satisfies Filament's HasTenants contract.
 *
 * The relation is named joinedTeams() because Spatie Permission's HasRoles
 * already defines teams() — both traits commonly sit on the same User.
 */
trait HasTeamMemberships
{
    public function joinedTeams(): BelongsToMany
    {
        $config = config('packstub-teams.membership');

        return $this->belongsToMany(
            config('packstub-teams.team_model'),
            $config['table'],
            $config['user_foreign_key'],
            $config['team_foreign_key'],
        )
            ->using(Membership::class)
            ->withPivot($config['role_column'])
            ->withTimestamps();
    }

    public function getTenants(Panel $panel): array|Collection
    {
        return $this->joinedTeams()->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->belongsToTeam($tenant);
    }

    public function belongsToTeam(Model $team): bool
    {
        return $this->joinedTeams()->whereKey($team->getKey())->exists();
    }

    public function teamRole(Model $team): ?string
    {
        $role = $this->joinedTeams()->whereKey($team->getKey())
            ->first()?->pivot?->getAttribute(config('packstub-teams.membership.role_column'));

        return $role === null ? null : (string) $role;
    }

    /**
     * @param  string|array<int, string>  $roles
     */
    public function hasTeamRole(Model $team, string|array $roles): bool
    {
        $role = $this->teamRole($team);

        return $role !== null && in_array($role, (array) $roles, true);
    }

    public function ownsTeam(Model $team): bool
    {
        return $this->teamRole($team) === Teams::ownerRole();
    }

    public function canManageTeam(Model $team): bool
    {
        return Teams::canManage($this, $team);
    }
}
