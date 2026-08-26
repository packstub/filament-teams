<?php

namespace Packstub\Teams\Roles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Roles come from config and live on the membership pivot only.
 */
class SimpleRoleProvider implements RoleProvider
{
    public function roles(Model $team): array
    {
        $owner = config('packstub-teams.roles.owner', 'owner');
        $roles = collect((array) config('packstub-teams.roles.roles', []))
            ->mapWithKeys(fn (string|array $role, string $name): array => [$name => is_array($role) ? ($role['label'] ?? Str::headline($name)) : $role])
            ->all();

        return [$owner => $roles[$owner] ?? 'Owner'] + $roles;
    }

    public function assigned(Model $user, Model $team, string $role): void {}

    public function removed(Model $user, Model $team): void {}

    public function can(Model $user, Model $team, string $role, string $permission): bool
    {
        $permissions = $this->permissions($role);

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public function describe(Model $team, string $role): ?string
    {
        $definition = config("packstub-teams.roles.roles.{$role}");

        return is_array($definition) ? ($definition['description'] ?? null) : null;
    }

    /**
     * @return array<int, string>
     */
    public function permissions(string $role): array
    {
        $definition = config("packstub-teams.roles.roles.{$role}");

        return is_array($definition) ? array_values((array) ($definition['permissions'] ?? [])) : [];
    }
}
