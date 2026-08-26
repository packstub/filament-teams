# Filament Teams

Teams, invitations and seat limits for Filament v5 panels with tenancy — with tenant-scoped roles that plug straight into **Filament Shield** / Spatie Permission when they're installed.

- **Members page** — invite by e-mail, change roles, remove, transfer ownership, leave.
- **Invitations** — signed links with expiry, resend, revoke, copy-link; guests are sent through login or registration and land in the team.
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
    ->acceptRoute();          // /{panel}/invitations/{token}
```

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

Every method throws a `Packstub\Teams\Exceptions\TeamsException` subclass on a rule violation (`SeatLimitReached`, `LastOwnerException`, `InvalidInvitation`, `InvalidRole`, `NotAMember`, `AlreadyAMember`); the Filament pages turn them into notifications.

Events: `MemberInvited`, `InvitationAccepted`, `MemberAdded`, `MemberRoleChanged`, `MemberRemoved`, `OwnershipTransferred`.

## Invitation flow

1. A manager invites an e-mail address with a role. A `TeamInvitation` is created (one pending per e-mail per team, re-inviting refreshes it) and `TeamInvitationMail` goes out.
2. The link hits `/{panel}/invitations/{token}`. Guests are redirected to the panel's login — or its registration page — with the intended URL kept; the invited e-mail is stored in the session under `Teams::SESSION_INVITATION` so you can prefill a registration form.
3. Signed in, the user joins with the invited role and lands on the team's dashboard. With `require_email_match` on (default) only the invited address may accept.

## Using it with Filament Tenancy

`packstub/filament-tenancy` already keeps a `tenant_user` pivot with a `role` column and an `owner` role. Point this plugin at it and skip the `create_team_user_table` migration:

```php
'team_model' => \App\Models\Tenant::class,
'membership' => [
    'table' => 'tenant_user',
    'team_foreign_key' => 'tenant_id',
    'user_foreign_key' => 'user_id',
    'role_column' => 'role',
],
```

## Testing

```bash
composer test
```

## License

Commercial — see [LICENSE.md](LICENSE.md). Support: support@packstub.dev.
