<?php

use Packstub\Teams\Facades\Teams;
use Packstub\Teams\TeamsPlugin;

it('applies settings per panel and restores keys another panel overrode', function () {
    $team = createTeam(createUser());

    // Values the test owns, so a restore is only satisfied by what was there before.
    config()->set('packstub-teams.connection', 'sentinel_connection');
    config()->set('packstub-teams.seat_limit', 7);
    config()->set('packstub-teams.membership.table', 'sentinel_members');
    config()->set('packstub-teams.invitations.max_batch', 42);

    $tenantPanel = TeamsPlugin::make()->useFilamentTenancy('central')->config(['seat_limit' => 9]);
    $plainPanel = TeamsPlugin::make()->config(['invitations.max_batch' => 3])->seatLimit(fn (): int => 4);

    $tenantPanel->configure();

    expect(config('packstub-teams.membership.table'))->toBe('tenant_user')
        ->and(config('packstub-teams.connection'))->toBe('central')
        ->and(config('packstub-teams.seat_limit'))->toBe(9);

    // A runtime change to a key no panel overrides survives the switch.
    config()->set('packstub-teams.invitations.links', false);

    $plainPanel->configure();

    expect(config('packstub-teams.membership.table'))->toBe('sentinel_members')
        ->and(config('packstub-teams.connection'))->toBe('sentinel_connection')
        ->and(config('packstub-teams.seat_limit'))->toBe(7)
        ->and(config('packstub-teams.invitations.max_batch'))->toBe(3)
        ->and(config('packstub-teams.invitations.links'))->toBeFalse()
        ->and(Teams::seatLimit($team))->toBe(4);

    $tenantPanel->configure();

    expect(config('packstub-teams.invitations.max_batch'))->toBe(42)
        ->and(Teams::seatLimit($team))->toBe(9);
});
