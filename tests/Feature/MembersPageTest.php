<?php

use Filament\Facades\Filament;
use Filament\Forms\Components\TagsInput;
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

it('invites several addresses from the tags input at once', function () {
    onTeam($this->owner, $this->team);

    livewire(Members::class)
        ->callTableAction('invite', data: ['emails' => ['leo@acme.test', 'Maya@Acme.test'], 'role' => 'member'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('2 invitations sent');

    expect($this->team->pendingInvitations()->pluck('email')->sort()->values()->all())->toBe(['leo@acme.test', 'maya@acme.test']);
    Mail::assertSent(TeamInvitationMail::class, 2);
});

it('names the invalid address and sends nothing until it is fixed', function () {
    onTeam($this->owner, $this->team);

    livewire(Members::class)
        ->callTableAction('invite', data: ['emails' => ['leo@acme.test', 'maya@acme', 'sam@acme.test'], 'role' => 'member'])
        ->assertHasTableActionErrors(['emails.1' => 'maya@acme is not a valid e-mail address.']);

    expect($this->team->pendingInvitations()->count())->toBe(0);
    Mail::assertNothingSent();
});

it('invites an address typed twice in different case once', function () {
    onTeam($this->owner, $this->team);

    livewire(Members::class)
        ->callTableAction('invite', data: ['emails' => ['Leo@acme.test', 'leo@acme.test'], 'role' => 'member'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Invitation sent to leo@acme.test');

    expect($this->team->pendingInvitations()->count())->toBe(1);
    Mail::assertSent(TeamInvitationMail::class, 1);
});

it('requires at least one address and rejects anything that is not one', function () {
    onTeam($this->owner, $this->team);

    livewire(Members::class)
        ->callTableAction('invite', data: ['emails' => [], 'role' => 'member'])
        ->assertHasTableActionErrors(['emails' => 'required']);

    livewire(Members::class)
        ->callTableAction('invite', data: ['emails' => [['x@acme.test']], 'role' => 'member'])
        ->assertHasTableActionErrors(['emails.0']);

    expect($this->team->pendingInvitations()->count())->toBe(0);
});

it('reports an address that already belongs to a member, whatever its case', function () {
    $this->member->forceFill(['email' => 'Mia@Example.com'])->save();
    onTeam($this->owner, $this->team);

    livewire(Members::class)
        ->callTableAction('invite', data: ['emails' => ['mia@example.com', 'new@example.com'], 'role' => 'member'])
        ->assertNotified('mia@example.com');

    expect($this->team->pendingInvitations()->pluck('email')->all())->toBe(['new@example.com']);
});

it('needs a free seat to resend an expired invitation from the invitations page', function () {
    config()->set('packstub-teams.seat_limit', 4);

    $expired = Teams::invite($this->team, 'jane@example.com');
    $expired->forceFill(['expires_at' => now()->subMinute()])->save();
    Teams::invite($this->team, 'sam@example.com');

    onTeam($this->owner, $this->team);

    livewire(Invitations::class)
        ->callTableAction('resend', $expired)
        ->assertNotified('The team has no available seats.');

    expect($expired->fresh()->isExpired())->toBeTrue();
});

it('never splits pasted addresses on the word Tab', function () {
    onTeam($this->owner, $this->team);

    $component = livewire(Members::class)->mountTableAction('invite');
    $schema = $component->instance()->getSchema($component->instance()->getMountedActionSchemaName());

    /** @var TagsInput $emails */
    $emails = $schema->getComponent(fn ($c) => $c instanceof TagsInput, withHidden: true);

    // Filament splits pastes on these as literal strings: "Tab" would cut "Tabitha@…".
    expect($emails->getSplitKeys())->not->toContain('Tab')
        ->toContain("\t")
        ->toContain(',');
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
