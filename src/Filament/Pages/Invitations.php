<?php

namespace Packstub\Teams\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Packstub\Teams\Exceptions\TeamsException;
use Packstub\Teams\Facades\Teams;
use Packstub\Teams\Filament\Actions\InviteMemberAction;
use Packstub\Teams\Models\TeamInvitation;
use Packstub\Teams\TeamsPlugin;

/**
 * Pending invitations for the current team — resend, revoke, copy the link.
 */
class Invitations extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelopeOpen;

    protected static ?string $slug = 'invitations';

    protected string $view = 'packstub-teams::pages.invitations';

    public static function getNavigationLabel(): string
    {
        return __('packstub-teams::teams.invitations.title');
    }

    public function getTitle(): string
    {
        return __('packstub-teams::teams.invitations.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return TeamsPlugin::get()->getNavigationGroup() ?? parent::getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        $sort = TeamsPlugin::get()->getNavigationSort();

        return $sort === null ? parent::getNavigationSort() : $sort + 1;
    }

    public static function getNavigationBadge(): ?string
    {
        $team = static::team();
        $count = $team ? Teams::invitationsOf($team)->pending()->count() : 0;

        return $count > 0 ? (string) $count : null;
    }

    public static function canAccess(): bool
    {
        $team = static::team();
        $user = Teams::currentUser();

        return parent::canAccess() && $team && $user && Teams::canInvite($user, $team);
    }

    public static function team(): ?Model
    {
        return Filament::getTenant();
    }

    public function table(Table $table): Table
    {
        $team = static::team();

        return $table
            ->query(fn (): Builder => TeamInvitation::query()->where('team_id', $team->getKey())->whereNull('accepted_at'))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('packstub-teams::teams.invitations.empty_heading'))
            ->emptyStateDescription(__('packstub-teams::teams.invitations.empty_description'))
            ->columns([
                TextColumn::make('email')
                    ->label(__('packstub-teams::teams.fields.email'))
                    ->searchable()
                    ->placeholder(__('packstub-teams::teams.invitations.link_row'))
                    ->description(fn (TeamInvitation $record): ?string => $record->isLink()
                        ? __('packstub-teams::teams.invitations.link_uses', ['uses' => $record->uses, 'max' => $record->max_uses ?? '∞'])
                        : null),
                TextColumn::make('role')
                    ->label(__('packstub-teams::teams.fields.role'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => Teams::roleLabel($team, $state)),
                TextColumn::make('inviter.name')
                    ->label(__('packstub-teams::teams.fields.invited_by'))
                    ->placeholder('—'),
                TextColumn::make('expires_at')
                    ->label(__('packstub-teams::teams.fields.expires'))
                    ->since()
                    ->placeholder(__('packstub-teams::teams.invitations.never_expires'))
                    ->color(fn (TeamInvitation $record): ?string => $record->isExpired() ? 'danger' : null),
            ])
            ->recordActions([
                Action::make('copyLink')
                    ->label(__('packstub-teams::teams.actions.copy_link'))
                    ->icon(Heroicon::OutlinedClipboard)
                    ->visible(fn (TeamInvitation $record): bool => $record->isPending())
                    ->modalHeading(__('packstub-teams::teams.actions.copy_link'))
                    ->modalContent(fn (TeamInvitation $record) => view('packstub-teams::invitation-link', ['url' => $record->acceptUrl()]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('packstub-teams::teams.actions.close')),
                Action::make('resend')
                    ->label(__('packstub-teams::teams.actions.resend'))
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->visible(fn (TeamInvitation $record): bool => ! $record->isLink())
                    ->disabled(fn (TeamInvitation $record): bool => $record->canResendAt() !== null)
                    ->tooltip(fn (TeamInvitation $record): ?string => ($at = $record->canResendAt())
                        ? __('packstub-teams::teams.errors.resend_too_soon', ['time' => $at->diffForHumans()])
                        : null)
                    ->action(function (TeamInvitation $record, Action $action): void {
                        try {
                            Teams::resend($record);
                        } catch (TeamsException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();

                            $action->halt();
                        }

                        Notification::make()->title(__('packstub-teams::teams.notifications.resent'))->success()->send();
                    }),
                Action::make('revoke')
                    ->label(__('packstub-teams::teams.actions.revoke'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (TeamInvitation $record): void {
                        Teams::revoke($record);

                        Notification::make()->title(__('packstub-teams::teams.notifications.revoked'))->success()->send();
                    }),
            ])
            ->headerActions([
                InviteMemberAction::make(),
                $this->createLinkAction(),
            ]);
    }

    protected function createLinkAction(): Action
    {
        $team = static::team();

        return Action::make('createLink')
            ->label(__('packstub-teams::teams.actions.create_link'))
            ->icon(Heroicon::OutlinedLink)
            ->color('gray')
            ->visible(fn (): bool => (bool) config('packstub-teams.invitations.links', true))
            ->modalWidth('md')
            ->modalDescription(__('packstub-teams::teams.invitations.create_link_description'))
            ->schema([
                Select::make('role')
                    ->label(__('packstub-teams::teams.fields.role'))
                    ->native(false)
                    ->options(Teams::assignableRoles($team))
                    ->default(Teams::defaultRoleFor($team))
                    ->required(),
                TextInput::make('max_uses')
                    ->label(__('packstub-teams::teams.fields.max_uses'))
                    ->helperText(__('packstub-teams::teams.fields.max_uses_help'))
                    ->numeric()
                    ->minValue(1),
                DateTimePicker::make('expires_at')
                    ->label(__('packstub-teams::teams.fields.expires'))
                    ->default(Teams::invitationExpiry())
                    ->minDate(now()),
            ])
            ->action(function (array $data, Action $action) use ($team): void {
                try {
                    $invitation = Teams::createInviteLink(
                        $team,
                        $data['role'],
                        filled($data['max_uses'] ?? null) ? (int) $data['max_uses'] : null,
                        filled($data['expires_at'] ?? null) ? Carbon::parse($data['expires_at']) : null,
                        Teams::currentUser(),
                    );
                } catch (TeamsException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    $action->halt();
                }

                Notification::make()
                    ->title(__('packstub-teams::teams.notifications.link_created'))
                    ->body($invitation->acceptUrl())
                    ->success()
                    ->persistent()
                    ->send();
            });
    }
}
