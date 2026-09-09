<?php

use Filament\Facades\Filament;
use Packstub\Teams\Facades\Teams;
use Packstub\Teams\Http\Middleware\SetPermissionsTeam;
use Packstub\Teams\Roles\SpatieRoleProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->owner = createUser();
    $this->acme = createTeam($this->owner, 'Acme');
    $this->globex = createTeam($this->owner, 'Globex');

    Permission::create(['name' => 'view_project']);
    Permission::create(['name' => 'delete_project']);

    // Global roles (team_id null) — the ones Shield creates by default.
    Role::create(['name' => 'editor'])->givePermissionTo('view_project');
    Role::create(['name' => 'admin'])->givePermissionTo(['view_project', 'delete_project']);

    // A role that exists only inside Globex.
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->globex->id);
    Role::create(['name' => 'auditor', 'team_id' => $this->globex->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('auto-selects the spatie provider when teams are enabled', function () {
    expect(config('permission.teams'))->toBeTrue()
        ->and(Teams::roleProvider())->toBeInstanceOf(SpatieRoleProvider::class)
        ->and(Teams::usesSpatieRoles())->toBeTrue();
});

it('lists global and team-scoped spatie roles with the reserved owner first', function () {
    expect(Teams::roles($this->acme))->toBe(['owner' => 'Owner', 'admin' => 'Admin', 'editor' => 'Editor'])
        ->and(Teams::roles($this->globex))->toBe(['owner' => 'Owner', 'admin' => 'Admin', 'auditor' => 'Auditor', 'editor' => 'Editor'])
        ->and(Teams::assignableRoles($this->acme))->not->toHaveKey('owner');
});

it('syncs a member role into spatie scoped to that team', function () {
    $user = createUser();
    Teams::addMember($this->acme, $user, 'editor');
    Teams::addMember($this->globex, $user, 'admin');

    $registrar = app(PermissionRegistrar::class);

    $registrar->setPermissionsTeamId($this->acme->id);
    expect($user->fresh()->getRoleNames()->all())->toBe(['editor'])
        ->and($user->fresh()->can('view_project'))->toBeTrue()
        ->and($user->fresh()->can('delete_project'))->toBeFalse();

    $registrar->setPermissionsTeamId($this->globex->id);
    expect($user->fresh()->getRoleNames()->all())->toBe(['admin'])
        ->and($user->fresh()->can('delete_project'))->toBeTrue();

    Teams::changeRole($this->globex, $user, 'editor');
    expect($user->fresh()->getRoleNames()->all())->toBe(['editor']);

    Teams::removeMember($this->globex, $user);
    expect($user->fresh()->getRoleNames()->all())->toBe([]);

    $registrar->setPermissionsTeamId($this->acme->id);
    expect($user->fresh()->getRoleNames()->all())->toBe(['editor']);
});

it('keeps the owner without a spatie role when none is named owner', function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->acme->id);

    expect($this->owner->fresh()->getRoleNames()->all())->toBe([])
        ->and(Teams::canManage($this->owner, $this->acme))->toBeTrue();

    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    Role::create(['name' => 'owner'])->givePermissionTo('delete_project');
    $team = createTeam($owner = createUser(), 'Initech');

    app(PermissionRegistrar::class)->setPermissionsTeamId($team->id);
    expect($owner->fresh()->getRoleNames()->all())->toBe(['owner'])
        ->and($owner->fresh()->can('delete_project'))->toBeTrue();
});

it('registers the tenant middleware that points spatie at the current tenant', function () {
    $user = createUser();
    Teams::addMember($this->acme, $user, 'admin');
    Teams::addMember($this->globex, $user, 'editor');

    $panel = Filament::getPanel('app');
    expect($panel->getTenantMiddleware())->toContain(SetPermissionsTeam::class);

    actingAs($user);
    Filament::setCurrentPanel($panel);

    Filament::setTenant($this->acme, isQuiet: true);
    (new SetPermissionsTeam)->handle(request(), fn () => null);
    expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe($this->acme->id)
        ->and($user->can('delete_project'))->toBeTrue();

    Filament::setTenant($this->globex, isQuiet: true);
    (new SetPermissionsTeam)->handle(request(), fn () => null);
    expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe($this->globex->id)
        ->and($user->can('delete_project'))->toBeFalse()
        ->and($user->can('view_project'))->toBeTrue();
});

it('applies team permissions on real page requests', function () {
    $user = createUser();
    Teams::addMember($this->acme, $user, 'admin');
    Teams::addMember($this->globex, $user, 'editor');

    $this->app['router']->get('/app/{tenant}/probe', fn () => auth()->user()->can('delete_project') ? 'yes' : 'no')
        ->middleware([...Filament::getPanel('app')->getMiddleware(), 'auth', ...Filament::getPanel('app')->getTenantMiddleware()])
        ->name('filament.app.probe');

    actingAs($user)->get("/app/{$this->acme->slug}/probe")->assertSee('yes');
    actingAs($user)->get("/app/{$this->globex->slug}/probe")->assertSee('no');
});

it('clones global template roles into a team and prefers the team copy', function () {
    $team = createTeam($owner = createUser(), 'Initech');

    $created = Teams::roleProvider()->cloneGlobalRolesToTeam($team);

    expect($created)->toBe(['editor', 'admin'])
        ->and(Teams::roleProvider()->cloneGlobalRolesToTeam($team))->toBe([])
        ->and(Role::query()->where('team_id', $team->id)->pluck('name')->sort()->values()->all())->toBe(['admin', 'editor'])
        ->and(Role::query()->where('team_id', $team->id)->where('name', 'admin')->first()->permissions->pluck('name')->sort()->values()->all())->toBe(['delete_project', 'view_project'])
        ->and(array_keys(Teams::roles($team)))->toBe(['owner', 'admin', 'editor']);

    $user = createUser();
    Teams::addMember($team, $user, 'admin');

    app(PermissionRegistrar::class)->setPermissionsTeamId($team->id);
    expect($user->fresh()->roles->first()->team_id)->toBe($team->id);
});

it('falls back to the first assignable role when the configured default is not a spatie role', function () {
    config()->set('packstub-teams.roles.default', 'stakeholder');

    expect(Teams::defaultRole())->toBe('stakeholder')
        ->and(Teams::defaultRoleFor($this->acme))->toBe('admin');

    $user = createUser();
    Teams::addMember($this->acme, $user);
    expect($this->acme->roleOf($user))->toBe('admin');

    config()->set('packstub-teams.roles.default', 'editor');
    expect(Teams::defaultRoleFor($this->acme))->toBe('editor');
});
