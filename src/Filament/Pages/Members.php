<?php

namespace Packstub\Teams\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Packstub\Teams\Exceptions\TeamsException;
use Packstub\Teams\Facades\Teams;
use Packstub\Teams\Filament\Actions\InviteMemberAction;
use Packstub\Teams\TeamsPlugin;

/**
 * Who belongs to the current team. Every member may look; managers invite,
 * change roles and remove; owners hand over ownership.
 */
class Members extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $slug = 'members';

    protected string $view = 'packstub-teams::pages.members';

    public static function getNavigationLabel(): string
    {
        return __('packstub-teams::teams.members.title');
    }

    public function getTitle(): string
    {
        return __('packstub-teams::teams.members.title');
    }

    public function getSubheading(): ?string
    {
        $team = static::team();
        $limit = $team ? Teams::seatLimit($team) : null;

        return $limit === null
            ? null
            : __('packstub-teams::teams.seats.summary', ['used' => Teams::seatsUsed($team), 'limit' => $limit]);
    }

    public static function getNavigationGroup(): ?string
    {
        return TeamsPlugin::get()->getNavigationGroup() ?? parent::getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return TeamsPlugin::get()->getNavigationSort() ?? parent::getNavigationSort();
    }

    public static function canAccess(): bool
    {
        $team = static::team();
        $user = Teams::currentUser();

        return parent::canAccess() && $team && $user && Teams::isMember($team, $user);
    }

    public static function team(): ?Model
    {
        return Filament::getTenant();
    }

    protected function canManage(): bool
    {
        return Teams::canManage(Teams::currentUser(), static::team());
    }

    public function table(Table $table): Table
    {
        $team = static::team();
        $roleColumn = config('packstub-teams.membership.role_column', 'role');

        return $table
            ->relationship(fn () => Teams::membersOf($team))
            ->inverseRelationship(Teams::userTeamsRelationName())
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('packstub-teams::teams.fields.name'))
                    ->searchable()
                    ->description(fn (Model $record): ?string => $record->email),
                TextColumn::make("pivot.{$roleColumn}")
                    ->label(__('packstub-teams::teams.fields.role'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Teams::roleLabel($team, $state))
                    ->color(fn (string $state): string => $state === Teams::ownerRole() ? 'primary' : 'gray'),
                TextColumn::make('pivot.created_at')
                    ->label(__('packstub-teams::teams.fields.joined'))
                    ->since(),
            ])
            ->recordActions([
                $this->changeRoleAction(),
                $this->transferOwnershipAction(),
                $this->removeAction(),
            ])
            ->headerActions([
                InviteMemberAction::make(),
                $this->leaveAction(),
            ]);
    }

    protected function changeRoleAction(): Action
    {
        $team = static::team();
        $roleColumn = config('packstub-teams.membership.role_column', 'role');

        return Action::make('changeRole')
            ->label(__('packstub-teams::teams.actions.change_role'))
            ->icon(Heroicon::OutlinedIdentification)
            ->visible(fn (Model $record): bool => $this->canManage() && $record->pivot->{$roleColumn} !== Teams::ownerRole())
            ->modalWidth('sm')
            ->fillForm(fn (Model $record): array => ['role' => $record->pivot->{$roleColumn}])
            ->schema([
                Select::make('role')
                    ->label(__('packstub-teams::teams.fields.role'))
                    ->native(false)
                    ->options(Teams::assignableRoles($team))
                    ->required(),
            ])
            ->action(function (Model $record, array $data, Action $action) use ($team): void {
                $this->guard(fn () => Teams::changeRole($team, $record, $data['role']), $action);

                Notification::make()->title(__('packstub-teams::teams.notifications.role_changed'))->success()->send();
            });
    }

    protected function transferOwnershipAction(): Action
    {
        $team = static::team();

        return Action::make('transferOwnership')
            ->label(__('packstub-teams::teams.actions.transfer_ownership'))
            ->icon(Heroicon::OutlinedArrowRightCircle)
            ->color('warning')
            ->visible(fn (Model $record): bool => ($user = Teams::currentUser()) && Teams::roleOf($team, $user) === Teams::ownerRole() && ! $record->is($user))
            ->requiresConfirmation()
            ->modalDescription(fn (Model $record): string => __('packstub-teams::teams.confirm.transfer_ownership', ['name' => $record->name]))
            ->action(function (Model $record, Action $action) use ($team): void {
                $this->guard(fn () => Teams::transferOwnership($team, Teams::currentUser(), $record), $action);

                Notification::make()->title(__('packstub-teams::teams.notifications.ownership_transferred'))->success()->send();
            });
    }

    protected function removeAction(): Action
    {
        $team = static::team();

        return Action::make('remove')
            ->label(__('packstub-teams::teams.actions.remove'))
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->visible(fn (Model $record): bool => $this->canManage() && ! $record->is(Teams::currentUser()))
            ->requiresConfirmation()
            ->modalDescription(fn (Model $record): string => __('packstub-teams::teams.confirm.remove', ['name' => $record->name]))
            ->action(function (Model $record, Action $action) use ($team): void {
                $this->guard(fn () => Teams::removeMember($team, $record), $action);

                Notification::make()->title(__('packstub-teams::teams.notifications.removed'))->success()->send();
            });
    }

    protected function leaveAction(): Action
    {
        $team = static::team();

        return Action::make('leave')
            ->label(__('packstub-teams::teams.actions.leave'))
            ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
            ->color('gray')
            ->visible(fn (): bool => Teams::roleOf($team, Teams::currentUser()) !== Teams::ownerRole() || Teams::ownersOf($team)->count() > 1)
            ->requiresConfirmation()
            ->modalDescription(__('packstub-teams::teams.confirm.leave'))
            ->action(function (Action $action) use ($team): void {
                $this->guard(fn () => Teams::removeMember($team, Teams::currentUser()), $action);

                $this->redirect(Filament::getCurrentPanel()->getUrl());
            });
    }

    protected function guard(callable $callback, Action $action): void
    {
        try {
            $callback();
        } catch (TeamsException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            $action->halt();
        }
    }
}
