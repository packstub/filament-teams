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

A member's role is read from the membership table once per request (or queued job) and remembered, so a page full of checks costs one query per user and team. Changes made through `Teams` (adding, removing, changing roles, accepting invitations, transferring ownership) and through the `members()` relation of `HasTeamMembers` are picked up straight away, and Octane and queue workers start each request and job afresh. If you write to the membership table directly, forget the remembered role yourself:

```php
Teams::forgetRole($team, $user); // one member
Teams::forgetRoles($team);       // every member of the team
Teams::forgetRolesOf($user);     // the user in every team
Teams::forgetRoles();            // everything
```

## Role providers

The `RoleProvider` decides where roles come from and what they mean:

| Provider | Roles | Permissions |
|---|---|---|
| `simple` (default) | the config list | the `permissions` arrays |
| `spatie` (auto when `permission.teams` is on) | Spatie Permission roles — global ones and the team's own | Spatie / Shield permissions for the current team |

Pick one explicitly with `roles.provider` or `TeamsPlugin::make()->roleProvider('spatie')`, or implement `Packstub\Teams\Roles\RoleProvider` yourself (`roles()`, `assigned()`, `removed()`, `can()`, `describe()`).

Read more: [Filament Shield →](shield.md)
