<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Packstub\Teams\Events\InvitationAccepted;
use Packstub\Teams\Events\MemberInvited;
use Packstub\Teams\Exceptions\AlreadyAMember;
use Packstub\Teams\Exceptions\InvalidInvitation;
use Packstub\Teams\Exceptions\InvalidRole;
use Packstub\Teams\Exceptions\SeatLimitReached;
use Packstub\Teams\Facades\Teams;
use Packstub\Teams\Mail\TeamInvitationMail;
use Packstub\Teams\Models\TeamInvitation;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    Mail::fake();
    $this->owner = createUser();
    $this->team = createTeam($this->owner);
});

it('creates a pending invitation and mails an accept link', function () {
    Event::fake([MemberInvited::class]);

    $invitation = Teams::invite($this->team, 'Jane@Example.com', 'admin', $this->owner);

    expect($invitation->email)->toBe('jane@example.com')
        ->and($invitation->role)->toBe('admin')
        ->and($invitation->invited_by)->toBe($this->owner->id)
        ->and($invitation->isPending())->toBeTrue()
        ->and($invitation->expires_at->isAfter(now()->addDays(6)))->toBeTrue()
        ->and($invitation->acceptUrl())->toBe("http://localhost/app/invitations/{$invitation->token}")
        ->and($this->team->pendingInvitations()->count())->toBe(1)
        ->and($this->team->seatsUsed())->toBe(2);

    Event::assertDispatched(MemberInvited::class);
    Mail::assertSent(TeamInvitationMail::class, fn (TeamInvitationMail $mail) => $mail->hasTo('jane@example.com')
        && $mail->acceptUrl === $invitation->acceptUrl()
        && $mail->teamName === 'Acme');
});

it('renders the invitation e-mail', function () {
    $invitation = Teams::invite($this->team, 'jane@example.com', 'admin', $this->owner);

    $html = (new TeamInvitationMail($invitation))->render();

    expect($html)->toContain('Join Acme')
        ->toContain($this->owner->name)
        ->toContain($invitation->acceptUrl());
});

it('refreshes an existing pending invitation instead of duplicating it', function () {
    $first = Teams::invite($this->team, 'jane@example.com');
    $second = Teams::invite($this->team, 'jane@example.com', 'admin');

    expect($second->id)->toBe($first->id)
        ->and($second->role)->toBe('admin')
        ->and($second->token)->not->toBe($first->token)
        ->and(TeamInvitation::count())->toBe(1);
});

it('validates the role, existing members and seats', function () {
    expect(fn () => Teams::invite($this->team, 'x@example.com', 'owner'))->toThrow(InvalidRole::class)
        ->and(fn () => Teams::invite($this->team, $this->owner->email))->toThrow(AlreadyAMember::class);

    config()->set('packstub-teams.seat_limit', 2);
    Teams::invite($this->team, 'a@example.com');

    expect(fn () => Teams::invite($this->team, 'b@example.com'))->toThrow(SeatLimitReached::class);

    // Re-inviting the seat holder is fine.
    Teams::invite($this->team, 'a@example.com');

    config()->set('packstub-teams.invitations.count_pending_toward_seats', false);
    expect($this->team->seatsUsed())->toBe(1);
    Teams::invite($this->team, 'b@example.com');
});

it('accepts an invitation for the matching user', function () {
    Event::fake([InvitationAccepted::class]);

    $invitation = Teams::invite($this->team, 'jane@example.com', 'admin');
    $jane = createUser(['email' => 'jane@example.com']);

    Teams::accept($invitation, $jane);

    expect($this->team->roleOf($jane))->toBe('admin')
        ->and($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($this->team->pendingInvitations()->count())->toBe(0)
        ->and($this->team->seatsUsed())->toBe(2);

    Event::assertDispatched(InvitationAccepted::class);

    expect(fn () => Teams::accept($invitation->fresh(), $jane))->toThrow(InvalidInvitation::class);
});

it('rejects a different e-mail unless email matching is disabled', function () {
    $invitation = Teams::invite($this->team, 'jane@example.com');
    $bob = createUser(['email' => 'bob@example.com']);

    expect(fn () => Teams::accept($invitation, $bob))->toThrow(InvalidInvitation::class);

    config()->set('packstub-teams.invitations.require_email_match', false);
    Teams::accept($invitation, $bob);

    expect($this->team->hasMember($bob))->toBeTrue();
});

it('rejects expired invitations and lets resend revive them', function () {
    $invitation = Teams::invite($this->team, 'jane@example.com');
    $invitation->forceFill(['expires_at' => now()->subMinute()])->save();
    $jane = createUser(['email' => 'jane@example.com']);

    expect($invitation->isExpired())->toBeTrue()
        ->and(Teams::findPendingInvitation($invitation->token))->toBeNull()
        ->and(fn () => Teams::accept($invitation, $jane))->toThrow(InvalidInvitation::class);

    $old = $invitation->token;
    Teams::resend($invitation);

    expect($invitation->token)->not->toBe($old)
        ->and($invitation->isPending())->toBeTrue();
    Mail::assertSent(TeamInvitationMail::class, 2);

    Teams::revoke($invitation);
    expect(TeamInvitation::count())->toBe(0);
});

it('sends guests to the login page and joins them after signing in', function () {
    $invitation = Teams::invite($this->team, 'jane@example.com');
    $url = $invitation->acceptUrl();

    get($url)
        ->assertRedirect('http://localhost/app/login')
        ->assertSessionHas('url.intended', $url)
        ->assertSessionHas(Packstub\Teams\Teams::SESSION_INVITATION.'.email', 'jane@example.com');

    $jane = createUser(['email' => 'jane@example.com']);

    actingAs($jane)->get($url)
        ->assertRedirect("http://localhost/app/{$this->team->slug}");

    expect($this->team->hasMember($jane))->toBeTrue();

    // Landing on the team afterwards works.
    actingAs($jane)->get("http://localhost/app/{$this->team->slug}")->assertOk();
});

it('bounces invalid tokens and mismatched users back to the panel', function () {
    get('http://localhost/app/invitations/nope')->assertRedirect('http://localhost/app');

    $invitation = Teams::invite($this->team, 'jane@example.com');
    $bob = createUser(['email' => 'bob@example.com']);

    $this->flushSession();
    actingAs($bob)->get($invitation->acceptUrl())->assertRedirect('http://localhost/app');
    expect($this->team->hasMember($bob))->toBeFalse();

    // A signed-in member opening someone else's link lands on their own team
    // (the panel home resolves to the user's default tenant).
    $this->flushSession();
    actingAs($this->owner)->get(Teams::invite($this->team, 'someone@example.com')->fresh()->acceptUrl())
        ->assertRedirect("http://localhost/app/{$this->team->slug}");

    // Already a member with a matching invitation: straight to the team.
    config()->set('packstub-teams.invitations.require_email_match', false);
    $this->flushSession();
    actingAs($this->owner)->get(Teams::invite($this->team, 'someone-else@example.com')->fresh()->acceptUrl())
        ->assertRedirect("http://localhost/app/{$this->team->slug}");
    expect($this->team->invitations()->where('email', 'someone-else@example.com')->exists())->toBeFalse();
});

it('queues the mail when configured', function () {
    config()->set('packstub-teams.invitations.mail.queue', true);

    Teams::invite($this->team, 'jane@example.com');

    Mail::assertQueued(TeamInvitationMail::class);
});
