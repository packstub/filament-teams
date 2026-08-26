<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Packstub\Teams\Facades\Teams;
use Packstub\Teams\TeamsPlugin;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->owner = createUser();
    $this->admin = createUser();
    $this->member = createUser();
    $this->team = createTeam($this->owner);
    Teams::addMember($this->team, $this->admin, 'admin');
    Teams::addMember($this->team, $this->member);
});

it('answers permissions from the config role definitions', function () {
    expect(Teams::userCan($this->owner, $this->team, 'anything'))->toBeTrue()
        ->and(Teams::userCan($this->admin, $this->team, 'delete'))->toBeTrue()
        ->and(Teams::userCan($this->member, $this->team, 'read'))->toBeTrue()
        ->and(Teams::userCan($this->member, $this->team, 'delete'))->toBeFalse()
        ->and(Teams::userCan(createUser(), $this->team, 'read'))->toBeFalse()
        ->and($this->member->hasTeamPermission($this->team, 'read'))->toBeTrue()
        ->and($this->member->hasTeamPermission($this->team, 'update'))->toBeFalse()
        ->and(Teams::roleDescription($this->team, 'member'))->toBe('Works inside the team.')
        ->and(Teams::roleDescriptions($this->team))->toHaveKeys(['admin', 'member'])
        ->and(Teams::roles($this->team))->toBe(['owner' => 'Owner', 'admin' => 'Admin', 'member' => 'Member']);
});

it('accepts plain string role definitions too', function () {
    config()->set('packstub-teams.roles.roles', ['owner' => 'Owner', 'editor' => 'Editor']);

    expect(Teams::roles($this->team))->toBe(['owner' => 'Owner', 'editor' => 'Editor'])
        ->and(Teams::roleDescription($this->team, 'editor'))->toBeNull()
        ->and(Teams::userCan($this->admin, $this->team, 'read'))->toBeFalse();
});

it('lets the plugin define roles jetstream style', function () {
    TeamsPlugin::make()
        ->roles([
            'owner' => ['label' => 'Owner', 'permissions' => ['*']],
            'editor' => ['label' => 'Editor', 'description' => 'Edits content', 'permissions' => ['posts.update']],
            'viewer' => 'Viewer',
        ], default: 'viewer', managers: ['editor'])
        ->register(Filament::getPanel('app'));

    expect(Teams::roles($this->team))->toBe(['owner' => 'Owner', 'editor' => 'Editor', 'viewer' => 'Viewer'])
        ->and(Teams::defaultRole())->toBe('viewer')
        ->and(Teams::managerRoles())->toBe(['owner', 'editor']);

    $editor = createUser();
    Teams::addMember($this->team, $editor, 'editor');

    expect(Teams::userCan($editor, $this->team, 'posts.update'))->toBeTrue()
        ->and(Teams::userCan($editor, $this->team, 'posts.delete'))->toBeFalse()
        ->and(Teams::canManage($editor, $this->team))->toBeTrue();
});

it('guards routes with the team.can middleware', function () {
    Route::get('/app/{tenant}/danger', fn () => 'ok')
        ->middleware([...Filament::getPanel('app')->getMiddleware(), 'auth', ...Filament::getPanel('app')->getTenantMiddleware(), 'team.can:delete'])
        ->name('filament.app.danger');

    actingAs($this->admin)->get("/app/{$this->team->slug}/danger")->assertOk();

    $this->flushSession();
    actingAs($this->member)->get("/app/{$this->team->slug}/danger")->assertForbidden();
});

it('exposes a @teamcan blade directive', function () {
    actingAs($this->member);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->team, isQuiet: true);

    $html = Blade::render("@teamcan('read') yes-read @endteamcan @teamcan('delete') yes-delete @else no-delete @endteamcan");

    expect($html)->toContain('yes-read')->toContain('no-delete')->not->toContain('yes-delete');
});
