# Configuration

`config/packstub-teams.php` holds app-wide settings; the plugin overrides any of them per panel.

| Key | Default | Purpose |
|---|---|---|
| `team_model` | `App\Models\Team` | the tenant model |
| `membership.table` / `team_foreign_key` / `user_foreign_key` / `role_column` | `team_user` / `team_id` / `user_id` / `role` | the pivot |
| `membership.team_relationship` / `user_relationship` | `members` / `joinedTeams` | relation names to prefer when your models define them |
| `invitations_table` | `team_invitations` | |
| `connection` | `null` | connection for the pivot + invitations (central DB in database-per-tenant apps) |
| `roles.provider` | `null` (auto) | `simple`, `spatie`, or a `RoleProvider` class |
| `roles.owner` / `roles.default` / `roles.managers` | `owner` / `member` / `['admin']` | reserved owner role, role for new invites, roles allowed to manage |
| `roles.roles` | owner/admin/member | `name => label` or `name => ['label', 'description', 'permissions']` |
| `invitations.expire_after_days` | 7 | |
| `invitations.require_email_match` | true | only the invited address may accept an e-mail invitation |
| `invitations.count_pending_toward_seats` | true | |
| `invitations.resend_cooldown_minutes` | 5 | |
| `invitations.max_batch` | 10 | addresses per Invite action |
| `invitations.links` | true | offer shareable links in the UI |
| `invitations.notify_existing_users` | true | database notification for invitees with an account |
| `invitations.auto_accept_on_login` | false | accept every pending invitation for the e-mail on login |
| `invitations.prune_accepted_after_days` | 30 | retention for `teams:prune-invitations` |
| `invitations.mail.queue` | false | |
| `personal_teams.enabled` / `name` | false / `:name's Team` | see [Installation](installation.md) |
| `seat_limit` | null | global default |

## The plugin API

```php
TeamsPlugin::make()
    ->roles([...], default: 'member', managers: ['admin'])
    ->roleProvider('spatie')                        // or 'simple' / a class
    ->seatLimit(fn (Team $team): ?int => $team->plan?->seats)
    ->canManageUsing(fn (User $user, Team $team): bool => ...)
    ->canInviteUsing(fn (User $user, Team $team): bool => ...)
    ->personalTeams(fn (User $user): Team => Team::create([...]), name: ":name's Team")
    ->navigationGroup('Team')->navigationSort(90)
    ->tenantMenuItem()                              // "Members" in the tenant switcher menu
    ->membersPage(MyMembers::class)                 // or null to skip
    ->invitationsPage(null)
    ->acceptRoute()                                 // /{panel}/invitations/{token}
    ->config(['seat_limit' => 10])                  // any packstub-teams.* key, per panel
    ->useFilamentTenancy();                         // preset for packstub/filament-tenancy
```

### Several panels

Every panel keeps its own `TeamsPlugin` settings. Filament registers all panels at boot, so console commands and queued jobs see the panel registered last; when a panel is served, its settings are applied again for that request. One app can therefore run a panel on Filament Tenancy's `Tenant` next to a panel on a plain `Team` model — set the keys that differ (`team_model`, `membership.*`, `invitations_table`, `connection`) explicitly on each panel. Keys a panel leaves unset come from the config file, never from another panel. When a seeder or test switches panels with `Filament::setCurrentPanel()`, call `TeamsPlugin::get()->configure()` afterwards so the runtime follows.

## The service

`Packstub\Teams\Facades\Teams` — `invite`, `inviteMany`, `createInviteLink`, `resend`, `revoke`, `accept`, `acceptAllFor`, `addMember`, `changeRole`, `removeMember`, `transferOwnership`, `roles`, `assignableRoles`, `defaultRoleFor`, `userCan`, `canManage`, `canInvite`, `seatLimit`, `seatsUsed`, `hasAvailableSeats`, `membersOf`, `teamsOf`, `invitationsOf`, `ownersOf`, `isMember`, `roleOf`, `pruneInvitations`, `createPersonalTeam`.

Rule violations throw a `Packstub\Teams\Exceptions\TeamsException` subclass: `SeatLimitReached`, `LastOwnerException`, `InvalidInvitation`, `InvalidRole`, `NotAMember`, `AlreadyAMember`, `ResendTooSoon`. The Filament pages turn them into notifications.
