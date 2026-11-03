<?php

use Filament\Facades\Filament;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Database\SqlServerConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Packstub\Teams\Events\MemberInvited;
use Packstub\Teams\Exceptions\AlreadyAMember;
use Packstub\Teams\Exceptions\InvalidEmail;
use Packstub\Teams\Exceptions\InvalidInvitation;
use Packstub\Teams\Exceptions\ResendTooSoon;
use Packstub\Teams\Exceptions\SeatLimitReached;
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

it('splits addresses joined with commas, semicolons or spaces', function () {
    $result = Teams::inviteMany($this->team, 'leo@acme.test,maya@acme.test; sam@acme.test  LEO@acme.test');

    expect(collect($result['invitations'])->pluck('email')->all())->toBe(['leo@acme.test', 'maya@acme.test', 'sam@acme.test'])
        ->and($result['errors'])->toBe([]);

    $result = Teams::inviteMany($this->team, ['ana@acme.test,bad@', 'tom@acme.test']);

    expect(collect($result['invitations'])->pluck('email')->all())->toBe(['ana@acme.test', 'tom@acme.test'])
        ->and(array_keys($result['errors']))->toBe(['bad@']);
});

it('fills the free seats and reports the addresses past the limit', function () {
    config()->set('packstub-teams.seat_limit', 3);

    $result = Teams::inviteMany($this->team, ['a@example.com', 'b@example.com', 'c@example.com']);

    expect(collect($result['invitations'])->pluck('email')->all())->toBe(['a@example.com', 'b@example.com'])
        ->and(array_keys($result['errors']))->toBe(['c@example.com'])
        ->and(Teams::seatsUsed($this->team))->toBe(3);

    // Re-inviting pending addresses needs no extra seat.
    config()->set('packstub-teams.invitations.resend_cooldown_minutes', 0);
    expect(Teams::inviteMany($this->team, ['A@example.com', 'b@example.com'])['errors'])->toBe([]);
});

it('treats an address as a member whatever its case', function () {
    Teams::addMember($this->team, $leo = createUser(['email' => 'Leo@Acme.test']));

    expect(fn () => Teams::invite($this->team, 'leo@acme.test'))->toThrow(AlreadyAMember::class)
        ->and(Teams::inviteMany($this->team, ['LEO@ACME.TEST'])['errors'])->toHaveKey('leo@acme.test')
        ->and(TeamInvitation::count())->toBe(0);
});

it('invites nothing and reports nothing for empty input', function (mixed $input) {
    expect(Teams::inviteMany($this->team, $input))->toBe(['invitations' => [], 'errors' => []])
        ->and(TeamInvitation::count())->toBe(0);
})->with([
    'empty list' => [[]],
    'empty string' => [''],
    'only separators' => [' , ; '],
]);

it('counts the batch limit after splitting', function () {
    config()->set('packstub-teams.invitations.max_batch', 10);

    $eleven = collect(range(1, 11))->map(fn (int $i): string => "user{$i}@acme.test")->implode(',');

    expect(fn () => Teams::inviteMany($this->team, $eleven))->toThrow(TeamsException::class)
        ->and(TeamInvitation::count())->toBe(0);
});

it('ignores items that are not addresses at all', function () {
    $result = Teams::inviteMany($this->team, [['x@acme.test'], 'leo@acme.test']);

    expect(collect($result['invitations'])->pluck('email')->all())->toBe(['leo@acme.test'])
        ->and($result['errors'])->toBe([]);
});

it('lets a member of another team be invited here, whatever the case', function () {
    $other = createTeam(createUser(), 'Globex');
    Teams::addMember($other, createUser(['email' => 'Leo@Acme.test']));

    expect(Teams::invite($this->team, 'LEO@acme.test')->email)->toBe('leo@acme.test');
});

it('rejects an invalid address before saving or sending anything', function () {
    Event::fake([MemberInvited::class]);

    expect(fn () => Teams::invite($this->team, 'bob@'))->toThrow(InvalidEmail::class, 'That is not a valid e-mail address.')
        ->and(TeamInvitation::count())->toBe(0);

    Event::assertNotDispatched(MemberInvited::class);
    Mail::assertNothingSent();
});

it('applies the resend cooldown when a pending address is invited again', function () {
    config()->set('packstub-teams.invitations.resend_cooldown_minutes', 5);

    $first = Teams::invite($this->team, 'leo@acme.test', 'member');

    expect(fn () => Teams::invite($this->team, 'Leo@acme.test', 'admin'))->toThrow(ResendTooSoon::class);

    $result = Teams::inviteMany($this->team, ['leo@acme.test', 'maya@acme.test'], 'admin');

    expect(collect($result['invitations'])->pluck('email')->all())->toBe(['maya@acme.test'])
        ->and($result['errors'])->toHaveKey('leo@acme.test')
        ->and($first->fresh()->token)->toBe($first->token)
        ->and($first->fresh()->role)->toBe('member');
    Mail::assertSent(TeamInvitationMail::class, 2);

    // Without sending, the invitation is updated in place.
    expect(Teams::invite($this->team, 'leo@acme.test', 'admin', send: false)->role)->toBe('admin');

    $this->travel(6)->minutes();

    Teams::invite($this->team, 'leo@acme.test', 'admin');
    Mail::assertSent(TeamInvitationMail::class, 3);
});

it('leaves the invitation untouched when accepting fails', function () {
    config()->set('packstub-teams.seat_limit', 1);
    $link = Teams::createInviteLink($this->team, 'member', maxUses: 1);

    expect(fn () => Teams::accept($link, createUser()))->toThrow(SeatLimitReached::class)
        ->and($link->uses)->toBe(0)
        ->and($link->accepted_at)->toBeNull()
        ->and($link->isPending())->toBeTrue();
});

it('gives an expired invitation a new link when it is updated without sending', function () {
    $first = Teams::invite($this->team, 'leo@acme.test', 'member');

    $this->travel(8)->days();

    $revived = Teams::invite($this->team, 'leo@acme.test', 'admin', send: false);

    expect($revived->token)->not->toBe($first->token)
        ->and($revived->isPending())->toBeTrue()
        ->and(Teams::findPendingInvitation($first->token))->toBeNull()
        ->and(Teams::findPendingInvitation($revived->token)?->is($first))->toBeTrue();
});

it('rethrows the original error when the invitation was revoked while accepting', function () {
    config()->set('packstub-teams.seat_limit', 1);
    $link = Teams::createInviteLink($this->team, 'member', maxUses: 1);
    TeamInvitation::query()->whereKey($link->getKey())->delete();

    expect(fn () => Teams::accept($link, createUser()))->toThrow(SeatLimitReached::class)
        ->and($link->uses)->toBe(0);
});

it('keeps the e-mailed link when a pending invitation is updated without sending', function () {
    $first = Teams::invite($this->team, 'leo@acme.test', 'member');

    $updated = Teams::invite($this->team, 'leo@acme.test', 'admin', send: false);

    expect($updated->token)->toBe($first->token)
        ->and($updated->role)->toBe('admin')
        ->and(Teams::findPendingInvitation($first->token)?->is($first))->toBeTrue();
    Mail::assertSent(TeamInvitationMail::class, 1);
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

    $existing = createUser(['email' => 'Jane@Example.com']);
    Notification::fake();

    Teams::invite($this->team, 'jane@example.com');
    Teams::invite($this->team, 'nobody@example.com');

    Notification::assertSentTo($existing, TeamInvitationNotification::class, function (TeamInvitationNotification $n) use ($existing) {
        $data = $n->toDatabase($existing);

        return str_contains($data['title'], 'Acme') && str_contains($data['actions'][0]['url'], '/app/invitations/');
    });
    Notification::assertCount(1);

    config()->set('packstub-teams.invitations.notify_existing_users', false);
    config()->set('packstub-teams.invitations.resend_cooldown_minutes', 0);
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
        ->personalTeams(fn (User $user): Team => Team::create(['name' => Teams::personalTeamName($user), 'slug' => 'personal-'.$user->id]), name: 'Home of :name')
        ->register(Filament::getPanel('app'));

    $user = createUser(['name' => 'Nina']);
    event(new Registered($user));

    $team = Teams::teamsOf($user)->first();
    expect($team)->not->toBeNull()
        ->and($team->name)->toBe('Home of Nina')
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

it('keeps the e-mail lookup indexable where the collation already ignores case', function (string $connectionClass, string $driver, string $expected) {
    $connection = new $connectionClass(fn () => null, 'db', '', ['driver' => $driver]);
    $query = (new Builder(new Illuminate\Database\Query\Builder($connection)))->setModel(new User);

    $query = (fn () => $this->whereEmail($query, 'users.email', ' Leo@Acme.test '))->call(app(TeamsService::class));

    expect($query->toSql())->toEndWith($expected)
        ->and($query->getBindings())->toBe(['leo@acme.test']);
})->with([
    'mysql' => [MySqlConnection::class, 'mysql', 'where `users`.`email` = ?'],
    'mariadb' => [MariaDbConnection::class, 'mariadb', 'where `users`.`email` = ?'],
    'sqlsrv' => [SqlServerConnection::class, 'sqlsrv', 'where [users].[email] = ?'],
    'pgsql' => [PostgresConnection::class, 'pgsql', 'where lower("users"."email") = ?'],
    'sqlite' => [SQLiteConnection::class, 'sqlite', 'where lower("users"."email") = ?'],
]);
