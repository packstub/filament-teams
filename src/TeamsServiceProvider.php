<?php

namespace Packstub\Teams;

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
}
