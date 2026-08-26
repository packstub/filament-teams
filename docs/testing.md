# Testing

The package ships 50+ Pest tests: membership rules, the invitation HTTP flow, Livewire page tests, the Spatie/Shield provider, string-keyed tenants and the Filament Tenancy preset.

In your own suite everything goes through the service, so most tests need no HTTP at all:

```php
it('lets an admin invite', function () {
    $team = Team::factory()->create();
    Teams::addMember($team, $owner = User::factory()->create(), Teams::ownerRole());

    Mail::fake();
    $invitation = Teams::invite($team, 'jane@acme.test', 'editor', $owner);

    Mail::assertSent(TeamInvitationMail::class);
    expect($invitation->acceptUrl())->toContain('/app/invitations/');
});
```

Livewire pages are testable with `pestphp/pest-plugin-livewire`:

```php
actingAs($owner);
Filament::setTenant($team, isQuiet: true);

livewire(Members::class)
    ->callTableAction('invite', data: ['emails' => ['jane@acme.test'], 'role' => 'editor'])
    ->assertNotified('Invitation sent to jane@acme.test');
```

For end-to-end coverage the demo app uses `pestphp/pest-plugin-browser`: invite from the Members page, follow the link as a guest, register, land in the team with the invited role, and see Shield permissions follow that role.
