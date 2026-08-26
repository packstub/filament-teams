<?php

namespace Packstub\Teams\Filament\Actions;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Packstub\Teams\Exceptions\TeamsException;
use Packstub\Teams\Facades\Teams;

/**
 * "Invite member" — usable on any page: Members, Invitations or your own.
 */
class InviteMemberAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'invite';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (): string => __('packstub-teams::teams.actions.invite'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->modalWidth('md')
            ->modalDescription(fn (): ?string => $this->seatsDescription())
            ->visible(fn (): bool => ($team = $this->team()) !== null && Teams::canInvite(Teams::currentUser(), $team))
            ->disabled(fn (): bool => ! $this->team()?->hasAvailableSeats())
            ->schema(fn (): array => [
                TextInput::make('email')
                    ->label(__('packstub-teams::teams.fields.email'))
                    ->email()
                    ->required()
                    ->autocomplete('off'),
                Select::make('role')
                    ->label(__('packstub-teams::teams.fields.role'))
                    ->options(Teams::assignableRoles($this->team()))
                    ->default(Teams::defaultRoleFor($this->team()))
                    ->required(),
            ])
            ->action(function (array $data, Action $action): void {
                try {
                    Teams::invite($this->team(), $data['email'], $data['role'], Teams::currentUser());
                } catch (TeamsException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    $action->halt();
                }

                Notification::make()
                    ->title(__('packstub-teams::teams.notifications.invited', ['email' => $data['email']]))
                    ->success()
                    ->send();
            });
    }

    protected function team(): ?Model
    {
        return Filament::getTenant();
    }

    protected function seatsDescription(): ?string
    {
        $team = $this->team();
        $limit = $team?->seatLimit();

        if ($team === null || $limit === null) {
            return null;
        }

        return __('packstub-teams::teams.seats.summary', ['used' => $team->seatsUsed(), 'limit' => $limit]);
    }
}
