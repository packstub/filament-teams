<?php

namespace Packstub\Teams;

use Closure;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Route;
use Packstub\Teams\Filament\Pages\Invitations;
use Packstub\Teams\Filament\Pages\Members;
use Packstub\Teams\Http\Controllers\AcceptInvitationController;
use Packstub\Teams\Http\Middleware\SetPermissionsTeam;
use Packstub\Teams\Roles\RoleProvider;

class TeamsPlugin implements Plugin
{
    public const ID = 'packstub-teams';

    /** @var class-string<Members>|null */
    protected ?string $membersPage = Members::class;

    /** @var class-string<Invitations>|null */
    protected ?string $invitationsPage = Invitations::class;

    protected string|Closure|null $navigationGroup = null;

    protected ?int $navigationSort = null;

    protected bool $tenantMenuItem = true;

    protected bool $acceptRoute = true;

    protected RoleProvider|string|null $roleProvider = null;

    protected ?Closure $seatLimitUsing = null;

    protected ?Closure $canManageUsing = null;

    protected ?Closure $canInviteUsing = null;

    /** @var array<string, mixed> */
    protected array $configOverrides = [];

    protected ?Closure $personalTeamUsing = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(static::ID);

        return $plugin;
    }

    public function getId(): string
    {
        return static::ID;
    }

    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------

    /**
     * @param  class-string<Members>|null  $page  null to skip registering the page
     */
    public function membersPage(?string $page): static
    {
        $this->membersPage = $page;

        return $this;
    }

    /**
     * @param  class-string<Invitations>|null  $page  null to skip registering the page
     */
    public function invitationsPage(?string $page): static
    {
        $this->invitationsPage = $page;

        return $this;
    }

    public function navigationGroup(string|Closure|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function navigationSort(?int $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    /**
     * Add a "Members" entry to the tenant menu.
     */
    public function tenantMenuItem(bool $enabled = true): static
    {
        $this->tenantMenuItem = $enabled;

        return $this;
    }

    /**
     * Register the /invitations/{token} accept route on this panel.
     */
    public function acceptRoute(bool $enabled = true): static
    {
        $this->acceptRoute = $enabled;

        return $this;
    }

    /**
     * @param  RoleProvider|class-string<RoleProvider>|'simple'|'spatie'|null  $provider
     */
    public function roleProvider(RoleProvider|string|null $provider): static
    {
        $this->roleProvider = $provider;

        return $this;
    }

    /**
     * fn (Model $team): ?int — null means unlimited.
     */
    public function seatLimit(?Closure $callback): static
    {
        $this->seatLimitUsing = $callback;

        return $this;
    }

    /**
     * fn (Model $user, Model $team): bool — who may change roles / remove members.
     */
    public function canManageUsing(?Closure $callback): static
    {
        $this->canManageUsing = $callback;

        return $this;
    }

    /**
     * fn (Model $user, Model $team): bool — who may invite (defaults to canManage).
     */
    public function canInviteUsing(?Closure $callback): static
    {
        $this->canInviteUsing = $callback;

        return $this;
    }

    /**
     * Define the roles for this panel, Jetstream style:
     * ['admin' => ['label' => 'Admin', 'description' => '…', 'permissions' => ['create', 'update']], …]
     *
     * @param  array<string, string|array<string, mixed>>  $roles
     */
    public function roles(array $roles, ?string $default = null, ?array $managers = null): static
    {
        $this->configOverrides['roles.roles'] = $roles;

        if ($default !== null) {
            $this->configOverrides['roles.default'] = $default;
        }

        if ($managers !== null) {
            $this->configOverrides['roles.managers'] = $managers;
        }

        return $this;
    }

    /**
     * Create a team for every new registration:
     * fn (Model $user): Model — return the saved team; the user becomes its owner.
     */
    public function personalTeams(?Closure $factory, ?string $name = null): static
    {
        $this->personalTeamUsing = $factory;
        $this->configOverrides['personal_teams.enabled'] = $factory !== null;

        if ($name !== null) {
            $this->configOverrides['personal_teams.name'] = $name;
        }

        return $this;
    }

    /**
     * Preset for packstub/filament-tenancy: reuse its Tenant model, the
     * `tenant_user` pivot (`tenant_id` / `role`) and its `users()` /
     * `tenants()` relations, and keep invitations on the central connection.
     */
    public function useFilamentTenancy(?string $connection = null): static
    {
        $this->configOverrides = [
            'team_model' => config('packstub-tenancy.tenant_model', 'Packstub\\Tenancy\\Models\\Tenant'),
            'membership.table' => 'tenant_user',
            'membership.team_foreign_key' => 'tenant_id',
            'membership.user_foreign_key' => 'user_id',
            'membership.role_column' => 'role',
            'membership.team_relationship' => 'users',
            'membership.user_relationship' => 'tenants',
            'connection' => $connection ?? config('packstub-teams.connection') ?? (config('tenancy.database.central_connection') ?: null),
        ];

        return $this;
    }

    /**
     * Override any `packstub-teams.*` config key for this panel's runtime.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function config(array $overrides): static
    {
        $this->configOverrides = [...$this->configOverrides, ...$overrides];

        return $this;
    }

    // ------------------------------------------------------------------
    // Accessors
    // ------------------------------------------------------------------

    public function getNavigationGroup(): ?string
    {
        $group = value($this->navigationGroup);

        return $group === null ? null : (string) $group;
    }

    public function getNavigationSort(): ?int
    {
        return $this->navigationSort;
    }

    /** @return class-string<Members>|null */
    public function getMembersPage(): ?string
    {
        return $this->membersPage;
    }

    /** @return class-string<Invitations>|null */
    public function getInvitationsPage(): ?string
    {
        return $this->invitationsPage;
    }

    // ------------------------------------------------------------------
    // Panel integration
    // ------------------------------------------------------------------

    public function register(Panel $panel): void
    {
        foreach ($this->configOverrides as $key => $value) {
            config()->set("packstub-teams.{$key}", $value);
        }

        $teams = app(Teams::class);

        if ($this->roleProvider !== null) {
            $teams->useRoleProvider(match ($this->roleProvider) {
                'simple' => Roles\SimpleRoleProvider::class,
                'spatie' => Roles\SpatieRoleProvider::class,
                default => $this->roleProvider,
            });
        }

        if ($this->seatLimitUsing) {
            $teams->seatLimitUsing($this->seatLimitUsing);
        }

        if ($this->canManageUsing) {
            $teams->canManageUsing($this->canManageUsing);
        }

        if ($this->canInviteUsing) {
            $teams->canInviteUsing($this->canInviteUsing);
        }

        if ($this->personalTeamUsing) {
            $teams->personalTeamUsing($this->personalTeamUsing);
        }

        $panel->pages(array_values(array_filter([$this->membersPage, $this->invitationsPage])));

        if ($this->acceptRoute) {
            $panel->routes(function (): void {
                Route::get('invitations/{token}', AcceptInvitationController::class)
                    ->middleware('throttle:30,1')
                    ->name('teams.invitations.accept');
            });
        }

        if ($teams->usesSpatieRoles()) {
            $panel->tenantMiddleware([SetPermissionsTeam::class], isPersistent: true);
        }

        if ($this->tenantMenuItem && $this->membersPage) {
            $page = $this->membersPage;

            $panel->tenantMenuItems([
                'members' => Action::make('members')
                    ->label(fn (): string => __('packstub-teams::teams.members.title'))
                    ->icon(Heroicon::OutlinedUsers)
                    ->url(fn (): string => $page::getUrl())
                    ->visible(fn (): bool => Filament::getTenant() !== null && $page::canAccess()),
            ]);
        }
    }

    public function boot(Panel $panel): void {}
}
