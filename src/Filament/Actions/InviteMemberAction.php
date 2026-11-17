<?php

namespace Packstub\Teams\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
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
            ->disabled(fn (): bool => ($team = $this->team()) === null || ! Teams::hasAvailableSeats($team))
            ->schema(fn (): array => [
                TagsInput::make('emails')
                    ->label(__('packstub-teams::teams.fields.emails'))
                    ->placeholder(__('packstub-teams::teams.fields.emails_placeholder'))
                    ->helperText(__('packstub-teams::teams.fields.emails_help', ['max' => (int) config('packstub-teams.invitations.max_batch', 10)]))
                    // No separator(): it would join the tags into one
                    // comma-separated string. The state stays an array.
                    // Filament also splits pasted text on these, as literal
                    // strings: never 'Tab' (it would cut "Tabitha@…"); "\t"
                    // splits a pasted spreadsheet row, and Tab still adds the
                    // tag on blur.
                    ->splitKeys([' ', ',', ';', "\t"])
                    ->nestedRecursiveRules([
                        fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if (! is_string($value)) {
                                $fail(__('packstub-teams::teams.errors.invalid_email'));

                                return;
                            }

                            if (! Teams::isValidEmail($value)) {
                                $fail(__('packstub-teams::teams.errors.invalid_email_named', ['email' => trim($value)]));
                            }
                        },
                    ])
                    ->required(),
                Select::make('role')
                    ->label(__('packstub-teams::teams.fields.role'))
                    ->native(false)
                    ->options(Teams::assignableRoles($this->team()))
                    ->default(Teams::defaultRoleFor($this->team()))
                    ->required(),
            ])
            ->action(function (array $data, Action $action): void {
                try {
                    $result = Teams::inviteMany($this->team(), (array) $data['emails'], $data['role'], Teams::currentUser());
                } catch (TeamsException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    $action->halt();
                }

                foreach ($result['errors'] as $email => $message) {
                    Notification::make()->title($email)->body($message)->danger()->send();
                }

                if ($result['invitations'] === []) {
                    $action->halt();
                }

                $sent = collect($result['invitations'])->pluck('email');

                Notification::make()
                    ->title($sent->count() === 1
                        ? __('packstub-teams::teams.notifications.invited', ['email' => $sent->first()])
                        : __('packstub-teams::teams.notifications.invited_many', ['count' => $sent->count()]))
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
        $limit = $team ? Teams::seatLimit($team) : null;

        if ($team === null || $limit === null) {
            return null;
        }

        return __('packstub-teams::teams.seats.summary', ['used' => Teams::seatsUsed($team), 'limit' => $limit]);
    }
}
