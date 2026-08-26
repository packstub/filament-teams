<?php

namespace Packstub\Teams\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Packstub\Teams\Teams;
use Packstub\Teams\Tests\Fixtures\TenantLikeTeam;
use Packstub\Teams\Tests\Fixtures\TenantLikeUser;

/**
 * Runs the plugin the way packstub/filament-tenancy apps do: string tenant
 * ids, the `tenant_user` pivot with `tenant_id`, models without the plugin's
 * traits, `users()` / `tenants()` relation names.
 */
abstract class TenancyShapedTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', TenantLikeUser::class);
        $app['config']->set('packstub-teams.team_model', TenantLikeTeam::class);
        $app['config']->set('packstub-teams.membership', [
            'table' => 'tenant_user',
            'team_foreign_key' => 'tenant_id',
            'user_foreign_key' => 'user_id',
            'role_column' => 'role',
            'team_relationship' => 'users',
            'user_relationship' => 'tenants',
        ]);
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

        Schema::create('tenants', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        foreach (['create_team_user_table', 'create_team_invitations_table'] as $migration) {
            (include __DIR__."/../database/migrations/{$migration}.php.stub")->up();
        }

        app(Teams::class)->useRoleProvider(null);
    }
}
