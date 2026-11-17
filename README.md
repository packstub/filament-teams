# Filament Teams

<div class="filament-hidden">

![Filament Teams — invite the team, roles per tenant](https://raw.githubusercontent.com/packstub/art/main/filament-teams/banner.jpg)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/packstub/filament-teams.svg?style=flat-square)](https://packagist.org/packages/packstub/filament-teams)
[![Tests](https://img.shields.io/github/actions/workflow/status/packstub/filament-teams/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/packstub/filament-teams/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/packstub/filament-teams.svg?style=flat-square)](https://packagist.org/packages/packstub/filament-teams)
[![License](https://img.shields.io/packagist/l/packstub/filament-teams.svg?style=flat-square)](https://github.com/packstub/filament-teams/blob/main/LICENSE.md)
[![Sponsor](https://img.shields.io/badge/sponsor-%E2%9D%A4-ea4aaa?style=flat-square&logo=githubsponsors&logoColor=white)](https://github.com/sponsors/icaliman)

</div>

Teams, invitations and seat limits for Filament v5 panels with tenancy — with tenant-scoped roles that plug straight into **Filament Shield** / Spatie Permission when they're installed.

![Inviting two teammates as editors from the Members page: the seat count goes from 5 to 7 of 8 and both wait on the Invitations page](https://raw.githubusercontent.com/packstub/art/main/filament-teams/docs/teams.gif)

## Features

- **[Members page](https://packstub.dev/docs/filament-teams/members)**: invite, change roles, remove members, transfer ownership or leave the team.
- **[Invitations](#invitation-flow)**: e-mail invitations and shareable links; guests sign in or register and land in the team.
- **[Seat limits](https://packstub.dev/docs/filament-teams/members#seats)**: a limit per team from config or the plan, pending invitations counted.
- **[Roles and permissions](#roles-and-permissions)**: roles that carry permissions, checked with `hasTeamPermission()`, `@teamcan` or a `team.can:` middleware.
- **[Filament Shield](#roles-with-filament-shield)**: Shield's roles per team, synced for each member automatically.
- **[Ownership rules](https://packstub.dev/docs/filament-teams/members#roles)**: the owner role is never assigned by hand, and the last owner stays.
- **[Personal teams](#personal-teams)**: an optional team for every new registration.
- **[Any tenancy](#using-it-with-filament-tenancy)**: Filament's own tenancy, or database per tenant with Filament Tenancy in one call.
- **[Built to extend](#the-teams-service)**: a `Teams` service that works without the traits, events, typed exceptions and translations.

## Compatibility

| Plugin | Filament | Laravel | PHP |
| --- | --- | --- | --- |
| 1.x | 5.x | 12.x, 13.x | 8.3+ |

## Installation

```bash
composer require packstub/filament-teams
php artisan packstub-teams:install
```

Put the traits on your models:

```php
use Filament\Models\Contracts\HasTenants;
use Packstub\Teams\Concerns\HasTeamMemberships;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    use HasTeamMemberships; // joinedTeams(), teamRole(), hasTeamRole(), ownsTeam(), getTenants(), canAccessTenant()
}
```

```php
use Packstub\Teams\Concerns\HasTeamMembers;

class Team extends Model
{
    use HasTeamMembers; // members(), owners(), invitations(), roleOf(), seatsUsed(), hasAvailableSeats()
}
```

Register the plugin on the tenant-aware panel:

```php
use Packstub\Teams\TeamsPlugin;

$panel
    ->tenant(Team::class, slugAttribute: 'slug')
    ->tenantRegistration(RegisterTeam::class)
    ->plugin(TeamsPlugin::make());
```

When a team is created, add its first owner:

```php
use Packstub\Teams\Facades\Teams;

$team = Team::create($data);
Teams::addMember($team, auth()->user(), Teams::ownerRole());
```

## Configuration

`config/packstub-teams.php`:

| Key | Purpose |
| --- | --- |
| `team_model` | the tenant model |
| `membership.table` / `team_foreign_key` / `user_foreign_key` / `role_column` | the pivot — set `tenant_user` / `tenant_id` to reuse Filament Tenancy's |
| `roles.provider` | `null` (auto), `simple`, `spatie`, or a `RoleProvider` class |
| `roles.owner`, `roles.default`, `roles.managers`, `roles.roles` | the reserved owner role, the role new invites get, which roles may manage members, and the config role list |
| `membership.team_relationship` / `user_relationship` | relation names on your models (`members` / `joinedTeams`; Tenancy: `users` / `tenants`) |
| `connection` | database connection for the pivot + invitations (central DB in database-per-tenant apps) |
| `invitations.expire_after_days`, `require_email_match`, `count_pending_toward_seats`, `mail.queue` | invitation behaviour |
| `seat_limit` | global default (null = unlimited) |

Per-panel options on the plugin:

```php
TeamsPlugin::make()
    ->seatLimit(fn (Team $team): ?int => $team->plan?->seats)     // null = unlimited
    ->canManageUsing(fn (User $user, Team $team): bool => ...)   // roles/removal
    ->canInviteUsing(fn (User $user, Team $team): bool => ...)   // defaults to canManage
    ->roleProvider('spatie')                                     // or 'simple' / a class
    ->navigationGroup('Team')
    ->navigationSort(90)
    ->tenantMenuItem()        // "Members" in the tenant switcher menu
    ->membersPage(MyMembers::class)      // or null to skip
    ->invitationsPage(null)
    ->acceptRoute()           // /{panel}/invitations/{token}
    ->config(['seat_limit' => 10])       // any packstub-teams.* key, per panel
    ->useFilamentTenancy();   // preset for packstub/filament-tenancy
```

## Roles and permissions

Roles are defined in config (or per panel) with a label, an optional description and the permissions they carry — owners hold everything, `*` grants all:

```php
TeamsPlugin::make()->roles([
    'owner'  => ['label' => 'Owner', 'permissions' => ['*']],
    'admin'  => ['label' => 'Admin', 'description' => 'Manages members and settings.', 'permissions' => ['create', 'read', 'update', 'delete']],
    'member' => ['label' => 'Member', 'permissions' => ['read']],
], default: 'member', managers: ['admin']);
```

Check them anywhere:

```php
$user->hasTeamPermission($team, 'update');      // HasTeamRoles / HasTeamMemberships
Teams::userCan($user, $team, 'update');
Route::get(...)->middleware('team.can:update');  // 403 otherwise, against the current Filament tenant
```

```blade
@teamcan('update') <x-filament::button>Edit</x-filament::button> @endteamcan
```

With the Spatie provider the same calls are answered by Spatie Permission / Shield for the current team.

## Roles with Filament Shield

Install [Filament Shield](https://github.com/bezhanSalleh/filament-shield) and enable Spatie Permission's `teams` feature (`config/permission.php` → `'teams' => true`, run the `add_teams_fields` migration, pass `scopeToTenant()` to Shield's plugin). That's all: the plugin detects `permission.teams`, switches to `SpatieRoleProvider`, and

- the Members page offers Shield's roles (global ones plus roles created inside the current team),
- assigning a role syncs the member's Spatie role *for that team*,
- a `SetPermissionsTeam` tenant middleware points Spatie at the current tenant on every request, so `$user->can()` and Shield's policies answer for the team you're in.

**Global vs. per-team roles.** Shield's Role resource is tenant-owned, so during a panel request Filament scopes the `Role` model to the current team and global roles (`team_id` null) are invisible to `$user->can()`. Treat global roles as templates and give each new team its own copies:

```php
// in your RegisterTenant page / team factory
Teams::roleProvider()->cloneGlobalRolesToTeam($team);
Teams::addMember($team, $user, Teams::ownerRole());
```

Your `Role` model needs a `team()` relation for Filament's scoping (`belongsTo(Team::class, 'team_id')`). When the configured default role doesn't exist among a team's roles, invites fall back to the first assignable one (`Teams::defaultRoleFor($team)`).

Prefer a fixed list instead? Set `roles.provider` to `simple` — roles live on the pivot only.

Write your own provider by implementing `Packstub\Teams\Roles\RoleProvider` (`roles()`, `assigned()`, `removed()`).

## The `Teams` service

```php
use Packstub\Teams\Facades\Teams;

Teams::invite($team, 'jane@example.com', 'editor', inviter: $user); // TeamInvitation (mail sent)
Teams::resend($invitation);
Teams::revoke($invitation);
Teams::accept($invitation, $user);

Teams::addMember($team, $user, 'member');
Teams::changeRole($team, $user, 'admin');
Teams::removeMember($team, $user);
Teams::transferOwnership($team, from: $owner, to: $user);

Teams::roles($team);           // ['owner' => 'Owner', 'admin' => 'Admin', ...]
Teams::assignableRoles($team); // without owner
Teams::canManage($user, $team);
Teams::seatLimit($team);
```

Also: `inviteMany()`, `createInviteLink()`, `acceptAllFor($user)`, `pendingInvitationFromSession()`, `pruneInvitations()`, `userCan()`, `roleDescription()`, `createPersonalTeam()`.

Every method throws a `Packstub\Teams\Exceptions\TeamsException` subclass on a rule violation (`SeatLimitReached`, `LastOwnerException`, `InvalidInvitation`, `InvalidRole`, `NotAMember`, `AlreadyAMember`, `ResendTooSoon`); the Filament pages turn them into notifications.

Events: `MemberInvited`, `InvitationAccepted`, `MemberAdded`, `MemberRoleChanged`, `MemberRemoved`, `OwnershipTransferred`.

## Invitation flow

1. A manager invites one or more e-mail addresses with a role (`Teams::invite()` / `inviteMany()`, up to `invitations.max_batch` at once). A `TeamInvitation` is created per address (one pending per e-mail per team, re-inviting refreshes it), `TeamInvitationMail` goes out, and invitees who already have an account also get a database notification in Filament's tray.
2. The link hits `/{panel}/invitations/{token}`. Guests are redirected to the panel's login — or its registration page — with the intended URL kept. Use `Packstub\Teams\Filament\Pages\Auth\Register` as the panel's registration page to prefill and lock the invited e-mail (`->registration(Register::class)`).
3. As soon as they log in or register, the invitation from the link is accepted (`AcceptPendingInvitations` listener); the accept route then sends them to the team's dashboard. With `require_email_match` on (default) only the invited address may accept an e-mail invitation. Turn on `invitations.auto_accept_on_login` to also accept every other pending invitation addressed to that e-mail.
4. **Shareable links** (`Teams::createInviteLink($team, $role, maxUses: 10, expiresAt: …)` or the "Create link" action) let anyone who signs in join with the chosen role until the link expires or its uses run out. Links never hold a seat.

Resending waits `invitations.resend_cooldown_minutes` between sends. Schedule `php artisan teams:prune-invitations` to delete expired invitations and accepted ones older than `invitations.prune_accepted_after_days`.

## Personal teams

Give every new account a team of its own — skipped for users who register through an invitation link:

```php
TeamsPlugin::make()->personalTeams(
    fn (User $user): Team => Team::create(['name' => Teams::personalTeamName($user), 'slug' => Str::slug($user->name).'-'.Str::random(4)]),
    name: ":name's Team",
);
```

## Using it with Filament Tenancy

[Filament Tenancy](https://packstub.dev/plugins/filament-tenancy) is a separate, paid Packstub plugin for database-per-tenant panels; Filament Teams works with or without it. When you use both, `packstub/filament-tenancy` already keeps a `tenant_user` pivot (`tenant_id`, `user_id`, `role`) with an `owner` role, string tenant ids and `users()` / `tenants()` relations. One call wires everything and keeps invitations on the central connection:

```php
$panel->plugins([
    TenancyPlugin::make()->/* … */,
    TeamsPlugin::make()->useFilamentTenancy(),   // optional: ->useFilamentTenancy('central')
]);
```

Then publish and run only the invitations migration (`create_team_invitations_table`) — the pivot already exists — and add `HasTeamRoles` to your user next to `HasPackstubTenants`:

```php
use Packstub\Teams\Concerns\HasTeamRoles;
use Packstub\Tenancy\Concerns\HasPackstubTenants;

class User extends Authenticatable implements FilamentUser, HasDefaultTenant, HasTenants
{
    use HasPackstubTenants, HasTeamRoles; // teamRole(), hasTeamRole(), ownsTeam(), canManageTeam()
}
```

Nothing on the `Tenant` model changes: the service reads the tenant's own `users()` relation. The accept link lives on the central host (`/{panel}/invitations/{token}`) and sends the new member to their tenant host afterwards — subdomains share the central session, custom domains go through Tenancy's handoff.

### Without the traits

The traits are conveniences. Every lookup is also available on the service for models you don't control — `Teams::membersOf($team)`, `teamsOf($user)`, `invitationsOf($team)`, `ownersOf($team)`, `isMember()`, `roleOf()`, `seatsUsed()`, `hasAvailableSeats()` — building the relations from `membership.*` config (`team_relationship` / `user_relationship` name the model relations to prefer when they exist). Team keys may be integers, UUIDs/ULIDs or plain strings; set `connection` to keep the pivot and invitations on a central database.

## Documentation

- [Installation](https://packstub.dev/docs/filament-teams/installation)
- [Members](https://packstub.dev/docs/filament-teams/members)
- [Invitations](https://packstub.dev/docs/filament-teams/invitations)
- [Roles and permissions](https://packstub.dev/docs/filament-teams/roles-and-permissions)
- [Roles with Filament Shield](https://packstub.dev/docs/filament-teams/shield)
- [Using it with Filament Tenancy](https://packstub.dev/docs/filament-teams/filament-tenancy)
- [Configuration](https://packstub.dev/docs/filament-teams/configuration)
- [Testing](https://packstub.dev/docs/filament-teams/testing)

The same pages live in the [`docs/`](https://github.com/packstub/filament-teams/tree/main/docs) directory of this repository.

## Testing

```bash
composer test
```

## Changelog

See the [changelog](https://github.com/packstub/filament-teams/blob/main/CHANGELOG.md).

## Contributing

See [CONTRIBUTING](https://github.com/packstub/filament-teams/blob/main/.github/CONTRIBUTING.md).

## Security

See [our security policy](https://github.com/packstub/filament-teams/security/policy).

## Credits

- [Ion Caliman](https://github.com/icaliman) and [Packstub](https://packstub.dev)
- [All contributors](https://github.com/packstub/filament-teams/graphs/contributors)

## License

MIT. See the [license file](https://github.com/packstub/filament-teams/blob/main/LICENSE.md).
