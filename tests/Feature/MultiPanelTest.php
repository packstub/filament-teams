<?php

use Packstub\Teams\Facades\Teams;
use Packstub\Teams\TeamsPlugin;

it('applies settings per panel and restores keys another panel overrode', function () {
    $team = createTeam(createUser());

    $tenantPanel = TeamsPlugin::make()->useFilamentTenancy('central')->config(['seat_limit' => 9]);
    $plainPanel = TeamsPlugin::make()->config(['invitations.max_batch' => 3])->seatLimit(fn (): int => 4);

    $tenantPanel->configure();

    expect(config('packstub-teams.membership.table'))->toBe('tenant_user')
        ->and(config('packstub-teams.connection'))->toBe('central')
        ->and(config('packstub-teams.seat_limit'))->toBe(9);

    // A runtime change to a key no panel overrides survives the switch.
    config()->set('packstub-teams.invitations.links', false);

    $plainPanel->configure();

    expect(config('packstub-teams.membership.table'))->toBe('team_user')
        ->and(config('packstub-teams.connection'))->toBeNull()
        ->and(config('packstub-teams.seat_limit'))->toBeNull()
        ->and(config('packstub-teams.invitations.max_batch'))->toBe(3)
        ->and(config('packstub-teams.invitations.links'))->toBeFalse()
        ->and(Teams::seatLimit($team))->toBe(4);

    $tenantPanel->configure();

    expect(config('packstub-teams.invitations.max_batch'))->toBe(10)
        ->and(Teams::seatLimit($team))->toBe(9);
});
