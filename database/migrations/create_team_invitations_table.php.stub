<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $teamModel = config('packstub-teams.team_model');
        $userModel = config('auth.providers.users.model');

        $team = new $teamModel;

        Schema::connection(config('packstub-teams.connection'))->create(config('packstub-teams.invitations_table', 'team_invitations'), function (Blueprint $table) use ($team, $userModel): void {
            $table->id();

            if ($team->getKeyType() === 'int') {
                $table->foreignIdFor($team, 'team_id')->constrained($team->getTable())->cascadeOnDelete();
            } else {
                $table->string('team_id');
                $table->foreign('team_id')->references($team->getKeyName())->on($team->getTable())->cascadeOnDelete();
            }

            // Null e-mail = shareable link.
            $table->string('email')->nullable();
            $table->string('role');
            $table->string('token', 64)->unique();
            $table->foreignIdFor($userModel, 'invited_by')->nullable()->constrained((new $userModel)->getTable())->nullOnDelete();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::connection(config('packstub-teams.connection'))->dropIfExists(config('packstub-teams.invitations_table', 'team_invitations'));
    }
};
