<?php

use Illuminate\Support\Facades\DB;
use Packstub\Teams\Facades\Teams;
use Packstub\Teams\Roles\RoleCache;

beforeEach(function () {
    $this->owner = createUser();
    $this->member = createUser(['email' => 'member@example.com']);
    $this->team = createTeam($this->owner);
    Teams::addMember($this->team, $this->member, 'member');
});

/**
 * Role lookups (selects on the pivot) run while $callback does.
 */
function roleQueries(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $count = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')
            && str_contains($query['query'], 'team_user'))
        ->count();

    DB::disableQueryLog();

    return $count;
}

it('asks the pivot once per user and team for repeated permission checks', function () {
    Teams::forgetRoles();

    $queries = roleQueries(function () {
        foreach (range(1, 10) as $ignored) {
            Teams::userCan($this->member, $this->team, 'read');
            Teams::canManage($this->member, $this->team);
            $this->member->hasTeamRole($this->team, 'member');
            Teams::roleOf($this->team, $this->member);
        }
    });

    expect($queries)->toBe(1);
});

it('remembers that a user is not a member', function () {
    $stranger = createUser();

    $queries = roleQueries(function () use ($stranger) {
        Teams::roleOf($this->team, $stranger);
        Teams::userCan($stranger, $this->team, 'read');
        Teams::canManage($stranger, $this->team);
    });

    expect($queries)->toBe(1)
        ->and(Teams::roleOf($this->team, $stranger))->toBeNull();
});

it('keeps roles apart per user and team', function () {
    $other = createTeam($this->member, 'Other');

    expect(Teams::roleOf($this->team, $this->member))->toBe('member')
        ->and(Teams::roleOf($other, $this->member))->toBe('owner')
        ->and(Teams::roleOf($this->team, $this->owner))->toBe('owner')
        ->and(Teams::roleOf($other, $this->owner))->toBeNull();
});

it('forgets the role when it changes', function () {
    expect(Teams::canManage($this->member, $this->team))->toBeFalse();

    Teams::changeRole($this->team, $this->member, 'admin');

    expect(Teams::roleOf($this->team, $this->member))->toBe('admin')
        ->and(Teams::canManage($this->member, $this->team))->toBeTrue();
});

it('forgets the role when the member is removed', function () {
    expect(Teams::userCan($this->member, $this->team, 'read'))->toBeTrue();

    Teams::removeMember($this->team, $this->member);

    expect(Teams::roleOf($this->team, $this->member))->toBeNull()
        ->and(Teams::userCan($this->member, $this->team, 'read'))->toBeFalse();
});

it('forgets the role when an invitation is accepted', function () {
    $invitee = createUser(['email' => 'invitee@example.com']);

    expect(Teams::roleOf($this->team, $invitee))->toBeNull();

    $invitation = Teams::invite($this->team, 'invitee@example.com', 'admin', send: false);
    Teams::accept($invitation, $invitee);

    expect(Teams::roleOf($this->team, $invitee))->toBe('admin')
        ->and(Teams::canManage($invitee, $this->team))->toBeTrue();
});

it('forgets both roles when ownership moves', function () {
    expect(Teams::roleOf($this->team, $this->owner))->toBe('owner')
        ->and(Teams::roleOf($this->team, $this->member))->toBe('member');

    Teams::transferOwnership($this->team, $this->owner, $this->member);

    expect(Teams::roleOf($this->team, $this->member))->toBe('owner')
        ->and(Teams::roleOf($this->team, $this->owner))->not->toBe('owner');
});

it('forgets roles when the pivot is written through the relation', function () {
    expect(Teams::roleOf($this->team, $this->member))->toBe('member');

    $this->team->members()->updateExistingPivot($this->member->getKey(), ['role' => 'admin']);

    expect(Teams::roleOf($this->team, $this->member))->toBe('admin');

    $this->team->members()->detach($this->member->getKey());

    expect(Teams::roleOf($this->team, $this->member))->toBeNull();

    $this->team->members()->attach($this->member->getKey(), ['role' => 'member']);

    expect(Teams::roleOf($this->team, $this->member))->toBe('member');
});

it('forgets roles on demand after a raw pivot write', function () {
    expect(Teams::roleOf($this->team, $this->member))->toBe('member');

    DB::table('team_user')->where('user_id', $this->member->getKey())->update(['role' => 'admin']);

    expect(Teams::roleOf($this->team, $this->member))->toBe('member');

    Teams::forgetRole($this->team, $this->member);

    expect(Teams::roleOf($this->team, $this->member))->toBe('admin');

    DB::table('team_user')->where('user_id', $this->member->getKey())->update(['role' => 'member']);
    Teams::forgetRoles($this->team);

    expect(Teams::roleOf($this->team, $this->member))->toBe('member');

    DB::table('team_user')->where('user_id', $this->member->getKey())->update(['role' => 'admin']);
    Teams::forgetRolesOf($this->member);

    expect(Teams::roleOf($this->team, $this->member))->toBe('admin');
});

it('forgets roles read inside a transaction that rolls back', function () {
    $invitee = createUser();

    try {
        DB::transaction(function () use ($invitee) {
            Teams::addMember($this->team, $invitee, 'admin');

            expect(Teams::roleOf($this->team, $invitee))->toBe('admin');

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(Teams::roleOf($this->team, $invitee))->toBeNull();
});

it('starts every request and queued job with no remembered roles', function () {
    $cache = app(RoleCache::class);

    expect(Teams::roleOf($this->team, $this->member))->toBe('member');

    DB::table('team_user')->where('user_id', $this->member->getKey())->update(['role' => 'admin']);

    // What Octane and the queue worker do between requests and jobs.
    app()->forgetScopedInstances();

    expect(app(RoleCache::class))->not->toBe($cache)
        ->and(Teams::roleOf($this->team, $this->member))->toBe('admin');
});
