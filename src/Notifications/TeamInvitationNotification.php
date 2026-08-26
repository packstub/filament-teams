<?php

namespace Packstub\Teams\Notifications;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use Packstub\Teams\Models\TeamInvitation;

/**
 * Database notification rendered by Filament's notification tray.
 */
class TeamInvitationNotification extends Notification
{
    public function __construct(public TeamInvitation $invitation) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $team = $this->invitation->team;
        $panel = Filament::getCurrentOrDefaultPanel();
        $name = $panel?->hasTenancy() ? Filament::getTenantName($team) : (string) ($team->name ?? $team->getKey());

        return FilamentNotification::make()
            ->title(__('packstub-teams::teams.mail.subject', ['team' => $name]))
            ->body(__('packstub-teams::teams.notifications.in_app_body', ['team' => $name, 'role' => $this->invitation->role]))
            ->icon('heroicon-o-envelope-open')
            ->actions([
                Action::make('accept')
                    ->label(__('packstub-teams::teams.mail.button'))
                    ->button()
                    ->url($this->invitation->acceptUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
