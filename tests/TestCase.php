<?php

namespace Packstub\Teams\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Packstub\Teams\Teams;
use Packstub\Teams\TeamsServiceProvider;
use Packstub\Teams\Tests\Fixtures\AppPanelProvider;
use Packstub\Teams\Tests\Fixtures\Team;
use Packstub\Teams\Tests\Fixtures\User;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use Spatie\Permission\PermissionServiceProvider;

abstract class TestCase extends Orchestra
{
    /** Set by tests that need Spatie team roles before the app boots. */
    protected static bool $spatieTeams = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrate();
    }

    protected function tearDown(): void
    {
        static::$spatieTeams = false;

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ActionsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            LivewireServiceProvider::class,
            PermissionServiceProvider::class,
            TeamsServiceProvider::class,
            AppPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('app.key', 'base64:2fl+Ktv6fZ7c7ZQfF1Zt6Q0Wd9jz5bJ6rKq8nX0m3Yk=');
        $app['config']->set('app.url', 'http://localhost');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('view.paths', [__DIR__.'/Fixtures/views']);
        $app['config']->set('packstub-teams.team_model', Team::class);
        $app['config']->set('permission.teams', static::$spatieTeams);
        $app['config']->set('permission.register_permission_check_method', true);
    }

    protected function migrate(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        foreach (['create_team_user_table', 'create_team_invitations_table'] as $migration) {
            (include __DIR__."/../database/migrations/{$migration}.php.stub")->up();
        }

        (include __DIR__.'/../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub')->up();

        app(Teams::class)->useRoleProvider(null);
    }
}
