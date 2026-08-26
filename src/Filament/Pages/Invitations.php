<?php

namespace Packstub\Teams\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
        $count = static::team()?->pendingInvitations()->count() ?? 0;

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
            ->query(fn (): Builder => TeamInvitation::query()->whereBelongsTo($team, 'team')->whereNull('accepted_at'))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('packstub-teams::teams.invitations.empty_heading'))
            ->emptyStateDescription(__('packstub-teams::teams.invitations.empty_description'))
            ->columns([
                TextColumn::make('email')
                    ->label(__('packstub-teams::teams.fields.email'))
                    ->searchable(),
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
                    ->action(function (TeamInvitation $record): void {
                        Teams::resend($record);

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
            ]);
    }
}
