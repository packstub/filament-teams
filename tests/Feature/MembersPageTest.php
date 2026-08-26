<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Packstub\Teams\Facades\Teams;
use Packstub\Teams\Filament\Pages\Invitations;
use Packstub\Teams\Filament\Pages\Members;
use Packstub\Teams\Mail\TeamInvitationMail;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Mail::fake();
    config()->set('packstub-teams.invitations.resend_cooldown_minutes', 0);

    $this->owner = createUser(['name' => 'Olivia Owner']);
    $this->admin = createUser(['name' => 'Adam Admin']);
    $this->member = createUser(['name' => 'Mia Member']);
    $this->team = createTeam($this->owner);
    Teams::addMember($this->team, $this->admin, 'admin');
    Teams::addMember($this->team, $this->member);
});

function onTeam($user, $team): void
{
    actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($team, isQuiet: true);
}

it('lists members with their roles for everyone on the team', function () {
    actingAs($this->member)
        ->get("http://localhost/app/{$this->team->slug}/members")
        ->assertOk()
        ->assertSee('Olivia Owner')
        ->assertSee('Adam Admin')
        ->assertSee('Mia Member');

    onTeam($this->member, $this->team);

    livewire(Members::class)
        ->assertCanSeeTableRecords([$this->owner, $this->admin, $this->member])
        ->assertTableActionHidden('changeRole', $this->admin)
        ->assertTableActionHidden('remove', $this->admin)
        ->assertTableActionHidden('transferOwnership', $this->admin)
        ->assertTableActionHidden('invite')
        ->assertTableActionVisible('leave');
});

it('keeps outsiders out', function () {
    $outsider = createUser();

    actingAs($outsider)->get("http://localhost/app/{$this->team->slug}/members")->assertNotFound();

    onTeam($outsider, $this->team);
    expect(Members::canAccess())->toBeFalse()
        ->and(Invitations::canAccess())->toBeFalse();
});

it('lets a manager invite, change roles and remove', function () {
    onTeam($this->admin, $this->team);

    livewire(Members::class)
        ->assertTableActionVisible('invite')
        ->callTableAction('invite', data: ['emails' => ['new@example.com'], 'role' => 'member'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Invitation sent to new@example.com');

    Mail::assertSent(TeamInvitationMail::class);
    expect($this->team->pendingInvitations()->count())->toBe(1);

    livewire(Members::class)
        ->callTableAction('changeRole', $this->member, ['role' => 'admin'])
        ->assertNotified('Role updated');
    expect($this->team->roleOf($this->member))->toBe('admin');

    livewire(Members::class)
        ->assertTableActionHidden('changeRole', $this->owner)
        ->assertTableActionHidden('transferOwnership', $this->member)
        ->assertTableActionHidden('remove', $this->admin)
        ->callTableAction('remove', $this->member)
        ->assertNotified('Member removed');
    expect($this->team->hasMember($this->member))->toBeFalse();
});

it('surfaces domain errors as notifications', function () {
    onTeam($this->owner, $this->team);

    livewire(Members::class)
        ->callTableAction('invite', data: ['emails' => [$this->member->email], 'role' => 'member'])
        ->assertNotified($this->member->email);

    config()->set('packstub-teams.seat_limit', 3);

    livewire(Members::class)->assertTableActionDisabled('invite');
});

it('lets the owner transfer ownership and then leave', function () {
    onTeam($this->owner, $this->team);

    livewire(Members::class)
        ->assertTableActionHidden('leave')
        ->assertTableActionVisible('transferOwnership', $this->admin)
        ->callTableAction('transferOwnership', $this->admin)
        ->assertNotified('Ownership transferred');

    expect($this->team->roleOf($this->admin))->toBe('owner')
        ->and($this->team->roleOf($this->owner))->toBe('admin');

    livewire(Members::class)
        ->assertTableActionVisible('leave')
        ->callTableAction('leave')
        ->assertRedirect('http://localhost/app');

    expect($this->team->hasMember($this->owner))->toBeFalse();
});

it('manages pending invitations', function () {
    $invitation = Teams::invite($this->team, 'jane@example.com', 'admin', $this->owner);

    actingAs($this->owner)
        ->get("http://localhost/app/{$this->team->slug}/invitations")
        ->assertOk()
        ->assertSee('jane@example.com')
        ->assertSee('Olivia Owner');

    $this->flushSession();
    actingAs($this->member)->get("http://localhost/app/{$this->team->slug}/invitations")->assertForbidden();

    onTeam($this->owner, $this->team);

    livewire(Invitations::class)
        ->assertCanSeeTableRecords([$invitation])
        ->callTableAction('resend', $invitation)
        ->assertNotified('Invitation sent again')
        ->callTableAction('revoke', $invitation)
        ->assertNotified('Invitation revoked');

    Mail::assertSent(TeamInvitationMail::class, 2);
    expect($this->team->invitations()->count())->toBe(0);
});

it('adds a members entry to the tenant menu', function () {
    onTeam($this->member, $this->team);

    $items = Filament::getPanel('app')->getTenantMenuItems();

    expect($items)->toHaveKey('members')
        ->and($items['members']->getUrl())->toBe("http://localhost/app/{$this->team->slug}/members");
});
