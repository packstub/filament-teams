<?php

namespace Packstub\Teams;

use Filament\Auth\Events\Registered as FilamentRegistered;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Packstub\Teams\Commands\PruneInvitationsCommand;
use Packstub\Teams\Events\InvitationAccepted;
use Packstub\Teams\Events\MemberAdded;
use Packstub\Teams\Events\MemberRemoved;
use Packstub\Teams\Events\MemberRoleChanged;
use Packstub\Teams\Events\OwnershipTransferred;
use Packstub\Teams\Http\Middleware\RequireTeamPermission;
use Packstub\Teams\Listeners\AcceptPendingInvitations;
use Packstub\Teams\Listeners\CreatePersonalTeam;
use Packstub\Teams\Roles\RoleCache;
use Packstub\Teams\Roles\RoleProvider;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class TeamsServiceProvider extends PackageServiceProvider
{
    public static string $name = 'packstub-teams';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasViews(static::$name)
            ->hasTranslations()
            ->hasMigrations(['create_team_user_table', 'create_team_invitations_table'])
            ->hasCommand(PruneInvitationsCommand::class)
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations();
            });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Teams::class);
        // Scoped, not a singleton: Octane and the queue worker drop scoped
        // instances between requests and jobs, so remembered roles never
        // carry over from one to the next.
        $this->app->scoped(RoleCache::class);
        $this->app->bind(RoleProvider::class, fn ($app): RoleProvider => $app->make(Teams::class)->roleProvider());
    }

    public function packageBooted(): void
    {
        // Filament's registration page fires its own event, not Laravel's.
        Event::listen([Login::class, Registered::class, FilamentRegistered::class], AcceptPendingInvitations::class);
        Event::listen([Registered::class, FilamentRegistered::class], CreatePersonalTeam::class);

        $this->forgetRolesOnMembershipEvents();

        $this->app['router']->aliasMiddleware('team.can', RequireTeamPermission::class);

        // @teamcan('update') … @endteamcan — against the current Filament tenant.
        Blade::if('teamcan', function (string $permission, $team = null): bool {
            $user = Filament::auth()->user() ?? auth()->user();
            $team ??= Filament::getTenant();

            return $user && $team && app(Teams::class)->userCan($user, $team, $permission);
        });
    }

    /**
     * Teams forgets a role when it changes one; these listeners also cover
     * the events when an app dispatches them itself, and a rolled-back
     * transaction, which may undo a membership whose role was read inside it.
     * The cache comes from app(), not $this->app: Octane serves each request
     * from a sandboxed copy of the application, and so does Teams.
     */
    protected function forgetRolesOnMembershipEvents(): void
    {
        $forget = function (Model $team, Model ...$users): void {
            foreach ($users as $user) {
                app(RoleCache::class)->forget($team, $user);
            }
        };

        Event::listen(MemberAdded::class, fn (MemberAdded $event) => $forget($event->team, $event->user));
        Event::listen(MemberRoleChanged::class, fn (MemberRoleChanged $event) => $forget($event->team, $event->user));
        Event::listen(MemberRemoved::class, fn (MemberRemoved $event) => $forget($event->team, $event->user));
        Event::listen(OwnershipTransferred::class, fn (OwnershipTransferred $event) => $forget($event->team, $event->from, $event->to));
        Event::listen(InvitationAccepted::class, fn (InvitationAccepted $event) => $forget($event->invitation->team, $event->user));
        Event::listen(TransactionRolledBack::class, fn () => app(RoleCache::class)->flush());
    }
}
