# Installation

## Requirements

| Package | Filament | Laravel | PHP |
|---|---|---|---|
| `1.x` | `^5.0` | `^12.0` / `^13.0` | `8.3+` |

Optional: `spatie/laravel-permission` (teams feature) and `bezhansalleh/filament-shield` for Shield-managed roles; `packstub/filament-tenancy` for database-per-tenant apps.

## Purchasing a license

Licenses are sold at [packstub.dev/plugins/filament-teams](https://packstub.dev/plugins/filament-teams). After purchase, create an access token on the **Install Guide** page of your Packstub dashboard — it shows the commands below with your token filled in.

## Installing with Composer

```bash
composer config repositories.packstub-filament-teams composer https://packstub.dev/composer/filament-teams
composer config --auth http-basic.packstub.dev pkg_xxxxxxxxxxxxxxxx your-token-secret
composer require packstub/filament-teams
php artisan packstub-teams:install
```

The installer publishes `config/packstub-teams.php` and two migrations: `create_team_user_table` (the membership pivot) and `create_team_invitations_table`. Already have a pivot — for example Filament Tenancy's `tenant_user`? Delete the first migration and point the config at yours ([Filament Tenancy →](filament-tenancy.md)).

## Models

```php
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Packstub\Teams\Concerns\HasTeamMemberships;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    use HasTeamMemberships; // joinedTeams(), teamRole(), hasTeamRole(), hasTeamPermission(), ownsTeam(), getTenants(), canAccessTenant()
}
```

```php
use Packstub\Teams\Concerns\HasTeamMembers;

class Team extends Model
{
    use HasTeamMembers; // members(), owners(), invitations(), roleOf(), seatsUsed(), hasAvailableSeats()
}
```

Both traits are conveniences: every lookup also exists on the `Teams` service (`Teams::membersOf()`, `teamsOf()`, `isMember()`, `roleOf()`, …) for models you don't control.

## Panel

```php
use Packstub\Teams\Filament\Pages\Auth\Register;
use Packstub\Teams\TeamsPlugin;

$panel
    ->tenant(Team::class, slugAttribute: 'slug')
    ->tenantRegistration(RegisterTeam::class)
    ->registration(Register::class)          // prefills the invited e-mail
    ->plugin(TeamsPlugin::make()->navigationGroup('Team'));
```

When a team is created, make its creator the owner:

```php
use Packstub\Teams\Facades\Teams;

$team = Team::create($data);
Teams::addMember($team, auth()->user(), Teams::ownerRole());
```

That's it — the **Members** and **Invitations** pages appear in the tenant navigation, the tenant menu gets a *Members* entry, and `/{panel}/invitations/{token}` handles every invitation link.

## Scheduling

Add the prune command to your scheduler to keep the invitations table small:

```php
Schedule::command('teams:prune-invitations')->daily();
```
