<?php

namespace Packstub\Teams\Concerns;

use Filament\Panel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Packstub\Teams\Models\Membership;

/**
 * For the user model. Also satisfies Filament's HasTenants contract.
 *
 * The relation is named joinedTeams() because Spatie Permission's HasRoles
 * already defines teams() — both traits commonly sit on the same User.
 * Already using another tenancy trait (e.g. HasPackstubTenants)? Use
 * HasTeamRoles instead and point `membership.user_relationship` at your
 * relation.
 */
trait HasTeamMemberships
{
    use HasTeamRoles;

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
}
