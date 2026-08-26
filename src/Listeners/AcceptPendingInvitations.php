<?php

namespace Packstub\Teams\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Eloquent\Model;
use Packstub\Teams\Exceptions\TeamsException;
use Packstub\Teams\Teams;

/**
 * Once someone signs in or registers, join the team whose link brought
 * them here (the token is kept in the session by the accept route). With
 * `auto_accept_on_login`, every pending invitation for their e-mail is
 * accepted too — no link required.
 */
class AcceptPendingInvitations
{
    public function __construct(protected Teams $teams) {}

    public function handle(Login|Registered $event): void
    {
        $user = $event->user;

        if (! $user instanceof Model) {
            return;
        }

        if ($invitation = $this->teams->pendingInvitationFromSession()) {
            try {
                $this->teams->accept($invitation, $user);
                session()->forget(Teams::SESSION_INVITATION);
                session()->put(Teams::SESSION_JOINED, $invitation->token);
            } catch (TeamsException) {
                // The accept route will explain when they land on it.
            }
        }

        if (config('packstub-teams.invitations.auto_accept_on_login', false)) {
            $this->teams->acceptAllFor($user);
        }
    }
}
