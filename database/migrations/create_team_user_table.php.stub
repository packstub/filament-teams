<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $config = config('packstub-teams.membership');
        $teamModel = config('packstub-teams.team_model');
        $userModel = config('auth.providers.users.model');

        $team = new $teamModel;

        Schema::connection(config('packstub-teams.connection'))->create($config['table'], function (Blueprint $table) use ($config, $team, $userModel): void {
            $table->id();

            // Mirror the team key type (bigint, UUID/ULID, or plain string ids
            // as used by packstub/filament-tenancy).
            if ($team->getKeyType() === 'int') {
                $table->foreignIdFor($team, $config['team_foreign_key'])->constrained($team->getTable())->cascadeOnDelete();
            } else {
                $table->string($config['team_foreign_key']);
                $table->foreign($config['team_foreign_key'])->references($team->getKeyName())->on($team->getTable())->cascadeOnDelete();
            }

            $table->foreignIdFor($userModel, $config['user_foreign_key'])->constrained((new $userModel)->getTable())->cascadeOnDelete();
            $table->string($config['role_column'])->default(config('packstub-teams.roles.default', 'member'));
            $table->timestamps();

            $table->unique([$config['team_foreign_key'], $config['user_foreign_key']]);
        });
    }

    public function down(): void
    {
        Schema::connection(config('packstub-teams.connection'))->dropIfExists(config('packstub-teams.membership.table'));
    }
};
