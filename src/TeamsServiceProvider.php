<?php

namespace Packstub\Teams;

use Filament\Facades\Filament;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Packstub\Teams\Commands\PruneInvitationsCommand;
use Packstub\Teams\Http\Middleware\RequireTeamPermission;
use Packstub\Teams\Listeners\AcceptPendingInvitations;
use Packstub\Teams\Listeners\CreatePersonalTeam;
use Packstub\Teams\Roles\RoleProvider;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class TeamsServiceProvider extends PackageServiceProvider
{
    public static string $name = 'packstub-teams';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasViews(static::$name)
            ->hasTranslations()
            ->hasMigrations(['create_team_user_table', 'create_team_invitations_table'])
            ->hasCommand(PruneInvitationsCommand::class)
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations();
            });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Teams::class);
        $this->app->bind(RoleProvider::class, fn ($app): RoleProvider => $app->make(Teams::class)->roleProvider());
    }

    public function packageBooted(): void
    {
        Event::listen([Login::class, Registered::class], AcceptPendingInvitations::class);
        Event::listen(Registered::class, CreatePersonalTeam::class);

        $this->app['router']->aliasMiddleware('team.can', RequireTeamPermission::class);

        // @teamcan('update') … @endteamcan — against the current Filament tenant.
        Blade::if('teamcan', function (string $permission, $team = null): bool {
            $user = Filament::auth()->user() ?? auth()->user();
            $team ??= Filament::getTenant();

            return $user && $team && app(Teams::class)->userCan($user, $team, $permission);
        });
    }
}
