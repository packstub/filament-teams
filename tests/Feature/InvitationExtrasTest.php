<?php

use Filament\Facades\Filament;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Packstub\Teams\Exceptions\AlreadyAMember;
use Packstub\Teams\Exceptions\InvalidInvitation;
use Packstub\Teams\Exceptions\ResendTooSoon;
use Packstub\Teams\Exceptions\TeamsException;
use Packstub\Teams\Facades\Teams;
use Packstub\Teams\Filament\Pages\Auth\Register;
use Packstub\Teams\Filament\Pages\Invitations;
use Packstub\Teams\Mail\TeamInvitationMail;
use Packstub\Teams\Models\TeamInvitation;
use Packstub\Teams\Notifications\TeamInvitationNotification;
use Packstub\Teams\Teams as TeamsService;
use Packstub\Teams\TeamsPlugin;
use Packstub\Teams\Tests\Fixtures\Team;
use Packstub\Teams\Tests\Fixtures\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Mail::fake();
    $this->owner = createUser();
    $this->team = createTeam($this->owner);
});

it('invites several addresses at once and reports the bad ones', function () {
    Teams::addMember($this->team, $existing = createUser(['email' => 'existing@example.com']));

    $result = Teams::inviteMany($this->team, ['a@example.com', ' B@Example.com ', 'not-an-email', 'existing@example.com', 'a@example.com'], 'admin', $this->owner);

    expect(collect($result['invitations'])->pluck('email')->all())->toBe(['a@example.com', 'b@example.com'])
        ->and($result['errors'])->toHaveKeys(['not-an-email', 'existing@example.com'])
        ->and(TeamInvitation::count())->toBe(2);

    Mail::assertSent(TeamInvitationMail::class, 2);

    config()->set('packstub-teams.invitations.max_batch', 2);
    expect(fn () => Teams::inviteMany($this->team, ['x@example.com', 'y@example.com', 'z@example.com']))->toThrow(TeamsException::class);
});

it('creates shareable links that any signed-in user may use until exhausted', function () {
    $link = Teams::createInviteLink($this->team, 'member', maxUses: 2, inviter: $this->owner);

    expect($link->isLink())->toBeTrue()
        ->and($link->isPending())->toBeTrue()
        ->and(Teams::seatsUsed($this->team))->toBe(1)
        ->and(Teams::findPendingInvitation($link->token))->not->toBeNull();
    Mail::assertNothingSent();

    $first = createUser();
    $second = createUser();
    $third = createUser();

    Teams::accept($link, $first);
    expect($link->uses)->toBe(1)->and($link->accepted_at)->toBeNull()->and(Teams::roleOf($this->team, $first))->toBe('member');

    // A member re-using the link is bounced but the link survives.
    expect(fn () => Teams::accept($link->fresh(), $first))->toThrow(AlreadyAMember::class)
        ->and(TeamInvitation::count())->toBe(1);

    actingAs($second)->get($link->acceptUrl())->assertRedirect("http://localhost/app/{$this->team->slug}");
    $link->refresh();
    expect($link->uses)->toBe(2)->and($link->isExhausted())->toBeTrue()->and($link->isPending())->toBeFalse();

    expect(fn () => Teams::accept($link, $third))->toThrow(InvalidInvitation::class)
        ->and(fn () => Teams::resend($link))->toThrow(InvalidInvitation::class);

    $this->flushSession();
    actingAs($third)->get($link->acceptUrl())->assertRedirect('http://localhost/app');
});

it('lets managers create links from the invitations page', function () {
    actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->team, isQuiet: true);

    livewire(Invitations::class)
        ->callTableAction('createLink', data: ['role' => 'member', 'max_uses' => 3])
        ->assertNotified('Invitation link created');

    $link = TeamInvitation::links()->firstOrFail();
    expect($link->max_uses)->toBe(3)->and($link->role)->toBe('member');

    livewire(Invitations::class)
        ->assertCanSeeTableRecords([$link])
        ->assertTableActionHidden('resend', $link)
        ->assertTableActionVisible('copyLink', $link);

    config()->set('packstub-teams.invitations.links', false);
    livewire(Invitations::class)->assertTableActionHidden('createLink');
});

it('enforces a resend cooldown', function () {
    config()->set('packstub-teams.invitations.resend_cooldown_minutes', 5);

    $invitation = Teams::invite($this->team, 'jane@example.com');

    expect($invitation->last_sent_at)->not->toBeNull()
        ->and($invitation->canResendAt())->not->toBeNull()
        ->and(fn () => Teams::resend($invitation))->toThrow(ResendTooSoon::class);

    $this->travel(6)->minutes();

    expect($invitation->fresh()->canResendAt())->toBeNull();
    Teams::resend($invitation->fresh());
    Mail::assertSent(TeamInvitationMail::class, 2);
});

it('notifies invitees who already have an account', function () {
    Schema::create('notifications', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('type');
        $table->morphs('notifiable');
        $table->text('data');
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });

    $existing = createUser(['email' => 'jane@example.com']);
    Notification::fake();

    Teams::invite($this->team, 'jane@example.com');
    Teams::invite($this->team, 'nobody@example.com');

    Notification::assertSentTo($existing, TeamInvitationNotification::class, function (TeamInvitationNotification $n) use ($existing) {
        $data = $n->toDatabase($existing);

        return str_contains($data['title'], 'Acme') && str_contains($data['actions'][0]['url'], '/app/invitations/');
    });
    Notification::assertCount(1);

    config()->set('packstub-teams.invitations.notify_existing_users', false);
    Teams::invite($this->team, 'jane@example.com', 'admin');
    Notification::assertCount(1);
});

it('accepts the invitation from the session on login and registration', function () {
    $invitation = Teams::invite($this->team, 'jane@example.com');
    get($invitation->acceptUrl());

    expect(Teams::pendingInvitationFromSession()?->is($invitation))->toBeTrue();

    $jane = createUser(['email' => 'jane@example.com']);
    event(new Login('web', $jane, false));

    expect(Teams::roleOf($this->team, $jane))->toBe('member')
        ->and(session()->has(TeamsService::SESSION_INVITATION))->toBeFalse();

    // Registration through the link works the same way.
    $other = Teams::invite($this->team, 'bob@example.com');
    get($other->acceptUrl());
    $bob = createUser(['email' => 'bob@example.com']);
    event(new Registered($bob));

    expect(Teams::roleOf($this->team, $bob))->toBe('member');
});

it('optionally accepts every pending invitation for the e-mail on login', function () {
    $second = createTeam(createUser(), 'Globex');
    Teams::invite($this->team, 'jane@example.com', 'admin');
    Teams::invite($second, 'jane@example.com');
    $jane = createUser(['email' => 'jane@example.com']);

    event(new Login('web', $jane, false));
    expect(Teams::teamsOf($jane)->count())->toBe(0);

    config()->set('packstub-teams.invitations.auto_accept_on_login', true);
    event(new Login('web', $jane, false));

    expect(Teams::teamsOf($jane)->count())->toBe(2)
        ->and(Teams::roleOf($this->team, $jane))->toBe('admin')
        ->and(TeamInvitation::pending()->count())->toBe(0);
});

it('prefills and locks the invited e-mail on the registration page', function () {
    $invitation = Teams::invite($this->team, 'jane@example.com');
    get($invitation->acceptUrl());

    $component = livewire(Register::class);
    $component->assertFormFieldExists('email', function ($field) {
        return $field->getDefaultState() === 'jane@example.com' && $field->isReadOnly();
    });

    session()->forget(TeamsService::SESSION_INVITATION);
    livewire(Register::class)->assertFormFieldExists('email', fn ($field) => ! $field->isReadOnly());
});

it('prunes expired and stale accepted invitations', function () {
    $expired = Teams::invite($this->team, 'old@example.com');
    $expired->forceFill(['expires_at' => now()->subDay()])->save();

    $accepted = Teams::invite($this->team, 'done@example.com');
    $accepted->forceFill(['accepted_at' => now()->subDays(40)])->save();

    $recent = Teams::invite($this->team, 'recent@example.com');
    $recent->forceFill(['accepted_at' => now()->subDay()])->save();

    Teams::invite($this->team, 'pending@example.com');

    Artisan::call('teams:prune-invitations');

    expect(Artisan::output())->toContain('Pruned 2')
        ->and(TeamInvitation::pluck('email')->sort()->values()->all())->toBe(['pending@example.com', 'recent@example.com']);
});

it('creates a personal team on registration unless the user came through an invitation', function () {
    TeamsPlugin::make()
        ->personalTeams(fn (User $user): Team => Team::create(['name' => Teams::personalTeamName($user), 'slug' => 'personal-'.$user->id]))
        ->register(Filament::getPanel('app'));

    $user = createUser(['name' => 'Nina']);
    event(new Registered($user));

    $team = Teams::teamsOf($user)->first();
    expect($team)->not->toBeNull()
        ->and($team->name)->toBe("Nina's Team")
        ->and(Teams::roleOf($team, $user))->toBe('owner');

    // Registering twice does not create a second team.
    event(new Registered($user));
    expect(Teams::teamsOf($user)->count())->toBe(1);

    // Invited users join the inviting team instead.
    $invitation = Teams::invite($this->team, 'invited@example.com');
    get($invitation->acceptUrl());
    $invited = createUser(['email' => 'invited@example.com']);
    event(new Registered($invited));

    expect(Teams::teamsOf($invited)->count())->toBe(1)
        ->and(Teams::teamsOf($invited)->first()->is($this->team))->toBeTrue();

    TeamsPlugin::make()->personalTeams(null)->register(Filament::getPanel('app'));
    expect(Teams::createsPersonalTeams())->toBeFalse();
});
