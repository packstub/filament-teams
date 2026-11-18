<?php

namespace Packstub\Teams\Concerns;

use Illuminate\Database\Eloquent\Model;
use Packstub\Teams\Facades\Teams;

/**
 * Role helpers for the user model that don't define any relation or
 * Filament tenancy method — safe next to another tenancy trait such as
 * packstub/filament-tenancy's HasPackstubTenants.
 */
trait HasTeamRoles
{
    /** A deleted user's remembered roles go with them. */
    public static function bootHasTeamRoles(): void
    {
        static::deleted(fn (Model $user) => Teams::forgetRolesOf($user));
    }

    public function belongsToTeam(Model $team): bool
    {
        return Teams::isMember($team, $this);
    }

    public function teamRole(Model $team): ?string
    {
        return Teams::roleOf($team, $this);
    }

    /**
     * @param  string|array<int, string>  $roles
     */
    public function hasTeamRole(Model $team, string|array $roles): bool
    {
        $role = $this->teamRole($team);

        return $role !== null && in_array($role, (array) $roles, true);
    }

    public function hasTeamPermission(Model $team, string $permission): bool
    {
        return Teams::userCan($this, $team, $permission);
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
