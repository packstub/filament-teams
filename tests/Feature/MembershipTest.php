<?php

use Illuminate\Support\Facades\Event;
use Packstub\Teams\Events\MemberAdded;
use Packstub\Teams\Events\MemberRemoved;
use Packstub\Teams\Events\MemberRoleChanged;
use Packstub\Teams\Events\OwnershipTransferred;
use Packstub\Teams\Exceptions\AlreadyAMember;
use Packstub\Teams\Exceptions\InvalidRole;
use Packstub\Teams\Exceptions\LastOwnerException;
use Packstub\Teams\Exceptions\NotAMember;
use Packstub\Teams\Exceptions\SeatLimitReached;
use Packstub\Teams\Facades\Teams;
use Packstub\Teams\Roles\SimpleRoleProvider;

it('falls back to the simple role provider without spatie teams', function () {
    config()->set('packstub-teams.roles.roles', ['owner' => 'Captain', 'lead' => 'Lead', 'contributor' => 'Contributor']);
    config()->set('packstub-teams.roles.default', 'contributor');

    expect(Teams::roleProvider())->toBeInstanceOf(SimpleRoleProvider::class)
        ->and(Teams::usesSpatieRoles())->toBeFalse()
        ->and(Teams::roles(createTeam()))->toBe(['owner' => 'Captain', 'lead' => 'Lead', 'contributor' => 'Contributor'])
        ->and(Teams::assignableRoles(createTeam()))->toBe(['lead' => 'Lead', 'contributor' => 'Contributor'])
        ->and(Teams::defaultRoleFor(createTeam()))->toBe('contributor');
});

it('adds members with a role and exposes it on both sides', function () {
    Event::fake([MemberAdded::class]);

    $owner = createUser();
    $member = createUser();
    $team = createTeam($owner);

    Teams::addMember($team, $member);

    expect($team->hasMember($member))->toBeTrue()
        ->and($team->roleOf($member))->toBe('member')
        ->and($team->roleOf($owner))->toBe('owner')
        ->and($team->isOwnedBy($owner))->toBeTrue()
        ->and($member->teamRole($team))->toBe('member')
        ->and($member->hasTeamRole($team, ['admin', 'member']))->toBeTrue()
        ->and($member->ownsTeam($team))->toBeFalse()
        ->and($member->belongsToTeam($team))->toBeTrue()
        ->and($member->canAccessTenant($team))->toBeTrue()
        ->and($member->getTenants(filament()->getPanel('app'))->pluck('id')->all())->toBe([$team->id])
        ->and($team->owners()->count())->toBe(1)
        ->and($team->seatsUsed())->toBe(2);

    Event::assertDispatched(MemberAdded::class, fn (MemberAdded $e) => $e->user->is($member) && $e->role === 'member');
});

it('rejects unknown roles and duplicate members', function () {
    $team = createTeam($owner = createUser());

    expect(fn () => Teams::addMember($team, createUser(), 'superuser'))->toThrow(InvalidRole::class)
        ->and(fn () => Teams::addMember($team, $owner))->toThrow(AlreadyAMember::class);
});

it('changes roles but never demotes the last owner', function () {
    Event::fake([MemberRoleChanged::class]);

    $owner = createUser();
    $member = createUser();
    $team = createTeam($owner);
    Teams::addMember($team, $member);

    Teams::changeRole($team, $member, 'admin');

    expect($team->roleOf($member))->toBe('admin')
        ->and(Teams::canManage($member, $team))->toBeTrue();

    Event::assertDispatched(MemberRoleChanged::class, fn (MemberRoleChanged $e) => $e->from === 'member' && $e->to === 'admin');

    expect(fn () => Teams::changeRole($team, $owner, 'admin'))->toThrow(LastOwnerException::class)
        ->and(fn () => Teams::changeRole($team, $member, 'owner'))->toThrow(InvalidRole::class)
        ->and(fn () => Teams::changeRole($team, createUser(), 'admin'))->toThrow(NotAMember::class);
});

it('removes members but never the last owner', function () {
    Event::fake([MemberRemoved::class]);

    $owner = createUser();
    $member = createUser();
    $team = createTeam($owner);
    Teams::addMember($team, $member);

    expect(fn () => Teams::removeMember($team, $owner))->toThrow(LastOwnerException::class);

    Teams::removeMember($team, $member);

    expect($team->hasMember($member))->toBeFalse();
    Event::assertDispatched(MemberRemoved::class);
});

it('transfers ownership and demotes the previous owner to admin', function () {
    Event::fake([OwnershipTransferred::class]);

    $owner = createUser();
    $member = createUser();
    $team = createTeam($owner);
    Teams::addMember($team, $member);

    expect(fn () => Teams::transferOwnership($team, $member, $owner))->toThrow(NotAMember::class)
        ->and(fn () => Teams::transferOwnership($team, $owner, createUser()))->toThrow(NotAMember::class);

    Teams::transferOwnership($team, $owner, $member);

    expect($team->roleOf($member))->toBe('owner')
        ->and($team->roleOf($owner))->toBe('admin')
        ->and($team->owners()->count())->toBe(1);

    Event::assertDispatched(OwnershipTransferred::class, fn (OwnershipTransferred $e) => $e->from->is($owner) && $e->to->is($member));
});

it('enforces seat limits from config and from the plugin callback', function () {
    config()->set('packstub-teams.seat_limit', 2);

    $team = createTeam(createUser());
    Teams::addMember($team, createUser());

    expect($team->seatLimit())->toBe(2)
        ->and($team->hasAvailableSeats())->toBeFalse()
        ->and(fn () => Teams::addMember($team, createUser()))->toThrow(SeatLimitReached::class);

    Teams::seatLimitUsing(fn ($t) => $t->is($team) ? 5 : null);

    expect($team->seatLimit())->toBe(5)
        ->and(createTeam()->seatLimit())->toBeNull();

    Teams::addMember($team, createUser());
    expect($team->seatsUsed())->toBe(3);

    Teams::seatLimitUsing(null);
});

it('decides who can manage from the manager roles or a callback', function () {
    $owner = createUser();
    $admin = createUser();
    $member = createUser();
    $team = createTeam($owner);
    Teams::addMember($team, $admin, 'admin');
    Teams::addMember($team, $member);

    expect(Teams::canManage($owner, $team))->toBeTrue()
        ->and(Teams::canManage($admin, $team))->toBeTrue()
        ->and(Teams::canManage($member, $team))->toBeFalse()
        ->and(Teams::canManage(createUser(), $team))->toBeFalse()
        ->and($member->canManageTeam($team))->toBeFalse();

    Teams::canManageUsing(fn ($user, $t) => $user->is($member));

    expect(Teams::canManage($member, $team))->toBeTrue()
        ->and(Teams::canManage($owner, $team))->toBeFalse();

    Teams::canManageUsing(null);
});
