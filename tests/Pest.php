<?php

use Illuminate\Support\Facades\Hash;
use Packstub\Teams\Facades\Teams;
use Packstub\Teams\Tests\Fixtures\Team;
use Packstub\Teams\Tests\Fixtures\User;
use Packstub\Teams\Tests\SpatieTestCase;
use Packstub\Teams\Tests\TenancyShapedTestCase;
use Packstub\Teams\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature/*.php');

/**
 * @param  array<string, mixed>  $attributes
 */
function createUser(array $attributes = []): User
{
    static $sequence = 0;

    $sequence++;

    return User::query()->create([
        'name' => "User {$sequence}",
        'email' => "user{$sequence}@example.com",
        'password' => Hash::make('secret'),
        ...$attributes,
    ]);
}

function createTeam(?User $owner = null, string $name = 'Acme'): Team
{
    static $sequence = 0;

    $sequence++;

    $team = Team::query()->create(['name' => $name, 'slug' => strtolower($name).'-'.$sequence]);

    if ($owner) {
        Teams::addMember($team, $owner, Teams::ownerRole());
    }

    return $team;
}

pest()->extend(SpatieTestCase::class)->in('Feature/Spatie');

pest()->extend(TenancyShapedTestCase::class)->in('Feature/Tenancy');
