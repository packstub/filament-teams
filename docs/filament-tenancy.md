# Using it with Filament Tenancy

[Filament Tenancy](https://packstub.dev/plugins/filament-tenancy) is a separate, paid Packstub plugin that gives each tenant its own database. Filament Teams is free and works with or without it; this page is for apps that use both. Install Tenancy first, following [its installation guide](https://packstub.dev/docs/filament-tenancy/installation).

Filament Tenancy already keeps a `tenant_user` pivot with `tenant_id`, `user_id` and `role`, an `owner` role, string tenant ids and `users()` / `tenants()` relations. One call wires everything and keeps invitations on the central connection:

```php
$panel->plugins([
    TenancyPlugin::make()->databasePool([...]),
    TeamsPlugin::make()->useFilamentTenancy()->navigationGroup('Workspace'),
]);
```

Then:

1. Publish and run only `create_team_invitations_table` (the pivot exists).
2. Add `HasTeamRoles` to your user next to `HasPackstubTenants`:

```php
class User extends Authenticatable implements FilamentUser, HasDefaultTenant, HasTenants
{
    use CentralConnection, HasPackstubTenants, HasTeamRoles;
}
```

Nothing on the `Tenant` model changes — the service reads the tenant's own `users()` relation.

## Hosts

The accept link lives on the **central** host (`https://myapp.test/admin/invitations/{token}`) and sends the new member to their tenant host afterwards. Subdomains share the central session; custom domains go through Tenancy's login handoff.

## Without the traits

Every lookup is available on the service for models you don't control — `Teams::membersOf($team)`, `teamsOf($user)`, `invitationsOf($team)`, `ownersOf($team)`, `isMember()`, `roleOf()`, `seatsUsed()`, `hasAvailableSeats()` — building the relations from `membership.*` config. Team keys may be integers, UUIDs/ULIDs or plain strings; `connection` keeps the pivot and invitations on a central database.
