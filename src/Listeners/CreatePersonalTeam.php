<?php

namespace Packstub\Teams\Listeners;

use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Eloquent\Model;
use Packstub\Teams\Teams;

/**
 * Give every new account a team of its own — unless they registered
 * through an invitation, in which case they join that team instead.
 */
class CreatePersonalTeam
{
    public function __construct(protected Teams $teams) {}

    public function handle(Registered $event): void
    {
        $user = $event->user;

        if (! $user instanceof Model || ! $this->teams->createsPersonalTeams()) {
            return;
        }

        if ($this->teams->pendingInvitationFromSession()) {
            return;
        }

        if ($this->teams->teamsOf($user)->exists()) {
            return;
        }

        $this->teams->createPersonalTeam($user);
    }
}
