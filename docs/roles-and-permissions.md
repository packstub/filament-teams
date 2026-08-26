# Roles and permissions

Roles are defined in config or per panel. Each carries a label, an optional description and the permissions it grants; owners implicitly hold every permission and `*` grants all.

```php
TeamsPlugin::make()->roles([
    'owner'  => ['label' => 'Owner', 'permissions' => ['*']],
    'admin'  => ['label' => 'Admin', 'description' => 'Manages members and settings.', 'permissions' => ['create', 'read', 'update', 'delete']],
    'editor' => ['label' => 'Editor', 'permissions' => ['create', 'read', 'update']],
    'viewer' => 'Viewer',
], default: 'viewer', managers: ['admin']);
```

## Checking

```php
$user->hasTeamRole($team, ['owner', 'admin']);
$user->hasTeamPermission($team, 'update');
Teams::userCan($user, $team, 'update');
```

```php
Route::get('/reports', ReportsController::class)->middleware('team.can:reports.view');
```

```blade
@teamcan('update')
    <x-filament::button>Edit</x-filament::button>
@endteamcan
```

All of them resolve against the current Filament tenant; pass a team explicitly to `@teamcan('update', $team)`.

## Role providers

The `RoleProvider` decides where roles come from and what they mean:

| Provider | Roles | Permissions |
|---|---|---|
| `simple` (default) | the config list | the `permissions` arrays |
| `spatie` (auto when `permission.teams` is on) | Spatie Permission roles — global ones and the team's own | Spatie / Shield permissions for the current team |

Pick one explicitly with `roles.provider` or `TeamsPlugin::make()->roleProvider('spatie')`, or implement `Packstub\Teams\Roles\RoleProvider` yourself (`roles()`, `assigned()`, `removed()`, `can()`, `describe()`).

Read more: [Filament Shield →](shield.md)
