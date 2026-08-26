<?php

namespace Packstub\Teams\Http\Controllers;

use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Packstub\Teams\Exceptions\AlreadyAMember;
use Packstub\Teams\Exceptions\TeamsException;
use Packstub\Teams\Teams;

/**
 * Landing route for invitation e-mails. Guests are sent to the panel's login
 * (or registration) and bounced back here afterwards; signed-in users join
 * the team and land on its dashboard.
 */
class AcceptInvitationController
{
    public function __invoke(Request $request, Teams $teams, string $token): RedirectResponse
    {
        $panel = Filament::getCurrentOrDefaultPanel();
        $invitation = $teams->findPendingInvitation($token);

        if (! $invitation) {
            $this->notify(__('packstub-teams::teams.notifications.invitation_invalid'), danger: true);

            return redirect()->to($panel->getUrl());
        }

        if (! Filament::auth()->check()) {
            $request->session()->put(Teams::SESSION_INVITATION, [
                'token' => $invitation->token,
                'email' => $invitation->email,
            ]);

            return redirect()->guest($panel->getLoginUrl() ?? $panel->getUrl());
        }

        $request->session()->forget(Teams::SESSION_INVITATION);

        $user = Filament::auth()->user();
        $team = $invitation->team;

        try {
            $teams->accept($invitation, $user);
        } catch (AlreadyAMember) {
            return redirect()->to($panel->getUrl($team));
        } catch (TeamsException $exception) {
            $this->notify($exception->getMessage(), danger: true);

            return redirect()->to($panel->getUrl());
        }

        $this->notify(__('packstub-teams::teams.notifications.joined', ['team' => Filament::getTenantName($team)]));

        return redirect()->to($panel->getUrl($team));
    }

    protected function notify(string $title, bool $danger = false): void
    {
        $notification = Notification::make()->title($title);

        $danger ? $notification->danger() : $notification->success();

        $notification->send();
    }
}
