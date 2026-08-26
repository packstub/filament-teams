# Packstub Teams

**Invite the team. Roles per tenant.**

Packstub Teams is a premium plugin that gives your **Filament v5** tenants everything a SaaS needs around its members: a Members page, e-mail and shareable-link invitations that walk guests through login or registration and drop them into the right team with the right role, seat limits driven by the plan, ownership rules, and roles that carry permissions — from a simple config list or from **Filament Shield**, per team. Filament's built-in tenancy and [Filament Tenancy](https://packstub.dev/plugins/filament-tenancy) (database per tenant) are both supported out of the box.

### Full documentation

Every guide — installation, members, invitations, roles and permissions, Shield, Filament Tenancy, configuration, testing — lives at **[packstub.dev/docs/filament-teams](README.md)**.

## Features

- **Members page** — every member sees the team; managers invite, change roles, remove members; owners transfer ownership; anyone can leave
- **E-mail invitations** — several addresses at once, expiry, resend cooldown, revoke, copy-link, and an in-app notification for invitees who already have an account
- **Shareable links** — join-with-this-link invitations with a role, an expiry and a use limit
- **Frictionless acceptance** — guests go through login or registration (invited e-mail prefilled and locked) and join the moment they sign in; pending invitations can be accepted automatically on login
- **Seat limits** — from config or from the plan, with pending invitations counted; the invite button disables when the team is full
- **Ownership rules** — a reserved owner role that is never assignable by hand and never removable while it is the last one
- **Roles with permissions** — Jetstream-style role definitions, `hasTeamPermission()`, a `team.can:` middleware and a `@teamcan` Blade directive
- **Filament Shield integration** — with Spatie Permission's teams feature on, the plugin lists Shield's roles, syncs a member's Spatie role per team and points `$user->can()` at the current tenant on every request
- **Personal teams** — optionally create a team for every new registration
- **Filament Tenancy preset** — `useFilamentTenancy()` reuses the `tenant_user` pivot, string tenant ids and the central connection
- **Works without traits** — every lookup is on the `Teams` service and builds relations from config, so models you don't control fit too
- **Events, exceptions, translations** — `MemberInvited`, `InvitationAccepted`, `MemberAdded`, `MemberRoleChanged`, `MemberRemoved`, `OwnershipTransferred`; typed `TeamsException`s; publishable views and translations
- **Housekeeping** — `teams:prune-invitations` for expired and stale invitations

## Screenshots

**Members** — roles per member, seats in the subheading, manager actions per row

<img src="https://packstub.dev/images/docs/filament-teams/members.png" alt="Members page with roles, seats and per-row actions">

**Invite** — several addresses at once with a role

<img src="https://packstub.dev/images/docs/filament-teams/invite-members.png" alt="Invite member modal with a tags input for addresses and a role select">

**Invitations** — pending e-mails and shareable links, resend and revoke

<img src="https://packstub.dev/images/docs/filament-teams/invitations.png" alt="Invitations page listing a pending invitation and a shareable link">

**Registration through an invitation** — the invited e-mail is prefilled and locked

<img src="https://packstub.dev/images/docs/filament-teams/register-invited.png" alt="Registration page with the invited e-mail prefilled and read-only">

**Shield roles per team** — the same user is an owner in one team and a viewer in another

<img src="https://packstub.dev/images/docs/filament-teams/shield-roles.png" alt="Filament Shield's Roles resource scoped to the current team">

## Installation

### Requirements

| Package | Filament | Laravel | PHP |
|---|---|---|---|
| `1.x` | `^5.0` | `^12.0` / `^13.0` | `8.3+` |

Optional: `spatie/laravel-permission` + `bezhansalleh/filament-shield` for Shield-managed roles; `packstub/filament-tenancy` for database-per-tenant apps.

### Purchasing a license

Licenses are sold at **[packstub.dev/plugins/filament-teams](https://packstub.dev/plugins/filament-teams)**. After purchase, sign in to your Packstub dashboard and create an access token on the **Install Guide** page — it shows the commands below with your token filled in.

### Installing with Composer

```bash
composer config repositories.packstub-filament-teams composer https://packstub.dev/composer/filament-teams
composer config --auth http-basic.packstub.dev pkg_xxxxxxxxxxxxxxxx your-token-secret
composer require packstub/filament-teams
php artisan packstub-teams:install
```

Full walkthrough: **[Installation →](installation.md)**

## Getting started

Two traits and one plugin call:

```php
// app/Models/User.php
class User extends Authenticatable implements FilamentUser, HasTenants
{
    use HasTeamMemberships;   // joinedTeams(), teamRole(), hasTeamPermission(), getTenants(), canAccessTenant()
}

// app/Models/Team.php
class Team extends Model
{
    use HasTeamMembers;       // members(), owners(), invitations(), seatsUsed()
}

// app/Providers/Filament/AppPanelProvider.php
$panel
    ->tenant(Team::class, slugAttribute: 'slug')
    ->registration(\Packstub\Teams\Filament\Pages\Auth\Register::class)
    ->plugin(TeamsPlugin::make()->seatLimit(fn (Team $team) => $team->plan?->seats));
```

Make the creator of a team its owner — `Teams::addMember($team, $user, Teams::ownerRole())` — and the Members and Invitations pages are live.

## Roles and permissions

```php
TeamsPlugin::make()->roles([
    'owner'  => ['label' => 'Owner', 'permissions' => ['*']],
    'admin'  => ['label' => 'Admin', 'permissions' => ['create', 'read', 'update', 'delete']],
    'editor' => ['label' => 'Editor', 'permissions' => ['create', 'read', 'update']],
    'viewer' => 'Viewer',
], default: 'viewer', managers: ['admin']);

$user->hasTeamPermission($team, 'update');
Route::get('/reports', ...)->middleware('team.can:reports.view');
```

```blade
@teamcan('update') <x-filament::button>Edit</x-filament::button> @endteamcan
```

Using **Filament Shield**? Enable Spatie's teams feature and the same calls are answered by Shield's roles for the current team — nothing else to wire. **[Filament Shield →](shield.md)**

## Invitations

```php
Teams::invite($team, 'jane@acme.test', 'editor', inviter: $user);
Teams::inviteMany($team, ['jane@acme.test', 'sam@acme.test'], 'viewer', $user);
Teams::createInviteLink($team, 'viewer', maxUses: 10, expiresAt: now()->addWeek());
```

Links land on `/{panel}/invitations/{token}`; guests are sent through login or registration and join as soon as they sign in. **[Invitations →](invitations.md)**

## Filament Tenancy

```php
$panel->plugins([
    TenancyPlugin::make(),
    TeamsPlugin::make()->useFilamentTenancy(),   // tenant_user pivot, string ids, central connection
]);
```

**[Using it with Filament Tenancy →](filament-tenancy.md)**

## Testing

The package ships 50+ Pest tests — membership rules, the invitation HTTP flow, Livewire page tests, the Spatie/Shield provider, string-keyed tenants — and its demo app runs Playwright end-to-end tests of the whole invite → register → join flow. Your own tests go through the `Teams` service, no HTTP needed. **[Testing →](testing.md)**

## Documentation

| Guide | What's inside |
|---|---|
| [Installation](installation.md) | Registry auth, installer, models, panel wiring |
| [Members](members.md) | Roles, seats, ownership, who may manage |
| [Invitations](invitations.md) | E-mail and link invitations, acceptance flow, housekeeping |
| [Roles and permissions](roles-and-permissions.md) | Role definitions, checks, providers |
| [Filament Shield](shield.md) | Spatie teams, per-team roles, template roles |
| [Filament Tenancy](filament-tenancy.md) | The database-per-tenant preset |
| [Configuration](configuration.md) | Every config key, the plugin API, the service |
| [Testing](testing.md) | Testing your own membership flows |

## Support

- **Email:** [support@packstub.dev](mailto:support@packstub.dev) — answered within one business day
- **Issues:** private issue tracker access comes with your license
- **Security:** report privately to [support@packstub.dev](mailto:support@packstub.dev)

## Changelog

Every release is documented in the package `CHANGELOG.md`. Config keys, the fluent API, and published views are treated as public API and follow semver.

## License

Packstub Teams is commercial software. Every license includes the full source code, **12 months of updates and support**, and a **perpetual license** for the versions you received.
