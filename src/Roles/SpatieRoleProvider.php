<?php

namespace Packstub\Teams\Roles;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Permission\Contracts\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tenant-scoped roles through Spatie Permission's `teams` feature — the same
 * roles Filament Shield manages. Assigning a member a role also syncs their
 * Spatie role for that team, so Shield-generated policies and permissions
 * apply per tenant.
 *
 * Roles listed are the guard's global roles (team_id null) plus the roles
 * created for this team; a team role wins over a global one of the same
 * name. The reserved owner role is always offered; when no Spatie role of
 * that name exists the owner keeps every ability through `Teams::canManage()`
 * only.
 *
 * Note that Filament scopes a tenant-owned Role resource (as Shield's is)
 * with a global scope during panel requests, which hides global roles. Use
 * `cloneGlobalRolesToTeam()` when a team is created so each team carries its
 * own copies of your template roles.
 */
class SpatieRoleProvider implements RoleProvider
{
    public function __construct(
        protected ?string $guard = null,
    ) {}

    /**
     * Resolved per call, never held: Spatie's helpers and traits resolve the
     * registrar from the container on every call, so we must talk to the
     * same instance they do.
     */
    protected function registrar(): PermissionRegistrar
    {
        return app(PermissionRegistrar::class);
    }

    public static function isAvailable(): bool
    {
        return class_exists(PermissionRegistrar::class)
            && (bool) config('permission.teams', false);
    }

    public function roles(Model $team): array
    {
        $labels = collect((array) config('packstub-teams.roles.roles', []))
            ->map(fn (string|array $role, string $name) => is_array($role) ? ($role['label'] ?? Str::headline($name)) : $role)
            ->all();
        $owner = config('packstub-teams.roles.owner', 'owner');

        $roles = $this->roleQuery($team)
            ->orderBy('name')
            ->pluck('name')
            ->unique()
            ->sort()
            ->values()
            ->mapWithKeys(fn (string $name): array => [$name => $labels[$name] ?? Str::headline($name)])
            ->all();

        return [$owner => $roles[$owner] ?? $labels[$owner] ?? 'Owner'] + $roles;
    }

    public function assigned(Model $user, Model $team, string $role): void
    {
        $this->withinTeam($team, function () use ($user, $role, $team): void {
            $spatieRole = $this->roleQuery($team)->where('name', $role)->first();

            $user->unsetRelation('roles');
            $user->syncRoles($spatieRole ? [$spatieRole] : []);
        });
    }

    public function removed(Model $user, Model $team): void
    {
        $this->withinTeam($team, function () use ($user): void {
            $user->unsetRelation('roles');
            $user->syncRoles([]);
        });
    }

    /**
     * Spatie answers: the member's roles for this team carry the permissions.
     */
    public function can(Model $user, Model $team, string $role, string $permission): bool
    {
        $result = false;

        $this->withinTeam($team, function () use ($user, $permission, &$result): void {
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $result = method_exists($user, 'hasPermissionTo')
                ? $user->checkPermissionTo($permission, $this->guard())
                : $user->can($permission);
        });

        return $result;
    }

    public function describe(Model $team, string $role): ?string
    {
        $definition = config("packstub-teams.roles.roles.{$role}");

        return is_array($definition) ? ($definition['description'] ?? null) : null;
    }

    /**
     * Copy every global role (team_id null) of the guard, with its
     * permissions, into the team — skipping names the team already has.
     *
     * @return array<int, string> names of the roles created
     */
    public function cloneGlobalRolesToTeam(Model $team): array
    {
        $teamKey = config('permission.column_names.team_foreign_key', 'team_id');
        $roleModel = $this->registrar()->getRoleClass();
        $created = [];

        $this->withinTeam($team, function () use ($team, $teamKey, $roleModel, &$created): void {
            $existing = $roleModel::query()->withoutGlobalScopes()
                ->where('guard_name', $this->guard())
                ->where($teamKey, $team->getKey())
                ->pluck('name')
                ->all();

            $templates = $roleModel::query()->withoutGlobalScopes()
                ->where('guard_name', $this->guard())
                ->whereNull($teamKey)
                ->with('permissions')
                ->get();

            foreach ($templates as $template) {
                if (in_array($template->name, $existing, true)) {
                    continue;
                }

                $role = $roleModel::query()->create([
                    'name' => $template->name,
                    'guard_name' => $template->guard_name,
                    $teamKey => $team->getKey(),
                ]);
                $role->syncPermissions($template->permissions);

                $created[] = $template->name;
            }
        });

        return $created;
    }

    protected function withinTeam(Model $team, callable $callback): void
    {
        $previous = $this->registrar()->getPermissionsTeamId();
        $this->registrar()->setPermissionsTeamId($team->getKey());

        try {
            $callback();
        } finally {
            $this->registrar()->setPermissionsTeamId($previous);
            $this->registrar()->forgetCachedPermissions();
        }
    }

    protected function roleQuery(Model $team): Builder
    {
        $teamKey = config('permission.column_names.team_foreign_key', 'team_id');
        $roleModel = $this->registrar()->getRoleClass();

        /** @var Builder $query */
        $query = $roleModel::query()
            ->where('guard_name', $this->guard())
            ->where(fn (Builder $q) => $q->whereNull($teamKey)->orWhere($teamKey, $team->getKey()))
            // A team's own role beats a global one of the same name.
            ->orderByRaw("{$teamKey} is null");

        return $query;
    }

    protected function guard(): string
    {
        return $this->guard ?? config('auth.defaults.guard', 'web');
    }
}
