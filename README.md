# Filament Teams

Teams, invitations and seat limits for Filament v5 panels with tenancy — with tenant-scoped roles that plug straight into **Filament Shield** / Spatie Permission when they're installed.

- **Members page** — invite by e-mail (several at once), change roles, remove, transfer ownership, leave.
- **Invitations** — e-mail invitations with expiry, resend cooldown, revoke, copy-link, in-app notification for existing users, **shareable links** with a use limit; guests are sent through login or registration (invited e-mail prefilled and locked) and land in the team; pending invitations are accepted automatically on login/registration.
- **Permissions** — roles carry permissions (`hasTeamPermission()`, `@teamcan`, `team.can:` middleware) from config, or from Spatie/Shield.
- **Personal teams** — optional team per new registration.
- **Seats** — a limit per team (from config, a plan, anything), with pending invitations counted.
- **Ownership rules** — a reserved owner role that is never assignable by hand and never removable while it is the last one.
- **Roles, your way** — a config list on the pivot, or Spatie Permission *teams* roles (the ones Shield manages) synced per tenant automatically.
- Works with Filament's native tenancy and with [Filament Tenancy](https://packstub.dev/plugins/filament-tenancy) (database-per-tenant) — the pivot table and keys are configurable.

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

`packstub/filament-tenancy` already keeps a `tenant_user` pivot (`tenant_id`, `user_id`, `role`) with an `owner` role, string tenant ids and `users()` / `tenants()` relations. One call wires everything and keeps invitations on the central connection:

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

## Testing

```bash
composer test
```

## License

Commercial — see [LICENSE.md](LICENSE.md). Support: support@packstub.dev.
