<?php

namespace Packstub\Teams\Roles;

use Illuminate\Database\Eloquent\Model;

/**
 * Roles come from config and live on the membership pivot only.
 */
class SimpleRoleProvider implements RoleProvider
{
    public function roles(Model $team): array
    {
        $roles = (array) config('packstub-teams.roles.roles', []);
        $owner = config('packstub-teams.roles.owner', 'owner');

        return [$owner => $roles[$owner] ?? 'Owner'] + $roles;
    }

    public function assigned(Model $user, Model $team, string $role): void {}

    public function removed(Model $user, Model $team): void {}
}
