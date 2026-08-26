<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Packstub\Teams\Facades\Teams;
use Packstub\Teams\Filament\Pages\Invitations;
use Packstub\Teams\Filament\Pages\Members;
use Packstub\Teams\Mail\TeamInvitationMail;
use Packstub\Teams\TeamsPlugin;
use Packstub\Teams\Tests\Fixtures\TenantLikeTeam;
use Packstub\Teams\Tests\Fixtures\TenantLikeUser;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

function tenantUser(string $name = 'Someone'): TenantLikeUser
{
    static $n = 0;
    $n++;

    return TenantLikeUser::create(['name' => $name, 'email' => "tenant-user{$n}@example.com", 'password' => bcrypt('secret')]);
}

beforeEach(function () {
    Mail::fake();
    $this->owner = tenantUser('Olivia Owner');
    $this->tenant = TenantLikeTeam::create(['name' => 'Acme Workspace', 'slug' => 'acme']);
    $this->tenant->users()->attach($this->owner, ['role' => 'owner']);
});

it('migrates string team keys onto the configured pivot and invitations table', function () {
    expect(Schema::getColumnType('tenant_user', 'tenant_id'))->toBe('varchar')
        ->and(Schema::getColumnType('team_invitations', 'team_id'))->toBe('varchar')
        ->and(Schema::hasTable('team_user'))->toBeFalse()
        ->and(strlen($this->tenant->getKey()))->toBe(36);
});

it('reads and writes memberships through the model\'s own relations', function () {
    $member = tenantUser('Mia Member');

    Teams::addMember($this->tenant, $member, 'member');
    Teams::addMember($this->tenant, $admin = tenantUser('Adam'), 'admin');

    expect(Teams::membersOf($this->tenant)->pluck('name')->all())->toContain('Mia Member')
        ->and($this->tenant->users()->count())->toBe(3)
        ->and(Teams::roleOf($this->tenant, $member))->toBe('member')
        ->and(Teams::isMember($this->tenant, $member))->toBeTrue()
        ->and(Teams::ownersOf($this->tenant)->count())->toBe(1)
        ->and(Teams::seatsUsed($this->tenant))->toBe(3)
        ->and(Teams::teamsOf($member)->first()->is($this->tenant))->toBeTrue()
        ->and(Teams::userTeamsRelationName())->toBe('tenants')
        ->and($member->teamRole($this->tenant))->toBe('member')
        ->and($member->belongsToTeam($this->tenant))->toBeTrue()
        ->and($member->ownsTeam($this->tenant))->toBeFalse()
        ->and($admin->canManageTeam($this->tenant))->toBeTrue()
        ->and($this->owner->ownsTeam($this->tenant))->toBeTrue();

    Teams::changeRole($this->tenant, $member, 'admin');
    Teams::transferOwnership($this->tenant, $this->owner, $member);
    Teams::removeMember($this->tenant, $admin);

    expect($member->fresh()->tenants()->first()->pivot->role)->toBe('owner')
        ->and(Teams::roleOf($this->tenant, $this->owner))->toBe('admin')
        ->and($this->tenant->users()->count())->toBe(2);
});

it('runs the invitation flow against a string-keyed tenant', function () {
    $invitation = Teams::invite($this->tenant, 'jane@example.com', 'admin', $this->owner);

    expect($invitation->team_id)->toBe($this->tenant->getKey())
        ->and($invitation->team->is($this->tenant))->toBeTrue()
        ->and(Teams::invitationsOf($this->tenant)->pending()->count())->toBe(1);

    Mail::assertSent(TeamInvitationMail::class, fn (TeamInvitationMail $mail) => $mail->teamName === 'Acme Workspace');

    $jane = tenantUser('Jane');
    $jane->forceFill(['email' => 'jane@example.com'])->save();

    actingAs($jane)->get($invitation->acceptUrl())->assertRedirect("http://localhost/app/{$this->tenant->slug}");

    expect(Teams::roleOf($this->tenant, $jane))->toBe('admin')
        ->and($jane->canAccessTenant($this->tenant))->toBeTrue();

    $this->flushSession();
    actingAs($jane)->get("http://localhost/app/{$this->tenant->slug}/members")->assertOk()->assertSee('Jane');
});

it('renders the members and invitations pages for trait-less models', function () {
    $member = tenantUser('Mia Member');
    Teams::addMember($this->tenant, $member, 'member');
    $invitation = Teams::invite($this->tenant, 'jane@example.com', 'member', $this->owner);

    actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->tenant, isQuiet: true);

    livewire(Members::class)
        ->assertCanSeeTableRecords([$this->owner, $member])
        ->assertTableActionVisible('invite')
        ->callTableAction('changeRole', $member, ['role' => 'admin'])
        ->assertNotified('Role updated');

    expect(Teams::roleOf($this->tenant, $member))->toBe('admin');

    livewire(Invitations::class)
        ->assertCanSeeTableRecords([$invitation])
        ->callTableAction('revoke', $invitation)
        ->assertNotified('Invitation revoked');

    $this->flushSession();
    actingAs($member)->get("http://localhost/app/{$this->tenant->slug}/members")->assertOk()->assertSee('Olivia Owner');
});

it('applies the filament-tenancy preset to config at register time', function () {
    config()->set('packstub-tenancy.tenant_model', TenantLikeTeam::class);
    config()->set('packstub-teams.membership.table', 'something_else');

    TeamsPlugin::make()->useFilamentTenancy('central')->config(['seat_limit' => 9])->register(Filament::getPanel('app'));

    expect(config('packstub-teams.team_model'))->toBe(TenantLikeTeam::class)
        ->and(config('packstub-teams.membership.table'))->toBe('tenant_user')
        ->and(config('packstub-teams.membership.team_foreign_key'))->toBe('tenant_id')
        ->and(config('packstub-teams.membership.team_relationship'))->toBe('users')
        ->and(config('packstub-teams.membership.user_relationship'))->toBe('tenants')
        ->and(config('packstub-teams.connection'))->toBe('central')
        ->and(config('packstub-teams.seat_limit'))->toBe(9);
});
