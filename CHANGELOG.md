# Changelog

All notable changes to `packstub/filament-teams` are documented here.

## Unreleased

Filament Teams is now free and open source under the MIT license.

- Install from Packagist with `composer require packstub/filament-teams`; no access token or private Composer repository needed
- **Upgrading:** if your `composer.json` has the `packstub-filament-teams` repository (`https://packstub.dev/composer/filament-teams`), remove it with `composer config --unset repositories.packstub-filament-teams`, then run `composer update packstub/filament-teams`
- Run several panels on different team models: `TeamsPlugin` settings now apply per panel, and unset ones fall back to the config file
- **Upgrading:** seeders and tests that switch panels with `Filament::setCurrentPanel()` call `TeamsPlugin::get()->configure()` afterwards
- Invite several addresses at once again: each is invited on its own, and a pasted list (commas, spaces, a spreadsheet row) is split for you
- Invalid addresses are named in the Invite form before anything is sent, and `Teams::invite()` throws `InvalidEmail` instead of saving a broken invitation
- Re-inviting waits for the resend cooldown, re-sending an expired invitation needs a free seat, and addresses match without regard to case
- The Role field is a searchable dropdown in Invite member, Change role and Create invite link
- **Upgrading:** `InviteMemberAction`'s `emails` form data is an array, `Teams::invite()` throws `ResendTooSoon` inside the cooldown, and an override of `inviteMany()` takes `iterable|string`
- Personal teams are created for people who sign up on the panel's own registration page, and an invitation followed before sign-up is accepted there too
- Docs: a shorter Features list, and notes on seat checks under concurrency and a `lower(email)` index on PostgreSQL
- `composer.json`'s homepage points at the package's page on packstub.dev

## 1.0.0 — 2026-08-26

First release of Filament Teams: members, invitations, roles and seats for your Filament panel.

- Members and Invitations pages: invite, change roles, remove, transfer ownership, leave
- Email invitations, bulk invites and shareable links, accepted on sign-in or sign-up
- Seat limits, with pending invitations counting toward seats
- Roles from config or Spatie Permission (the roles Filament Shield manages), each with its permissions
- Uses Filament Tenancy's `tenant_user` pivot out of the box, or your own models without the traits
- Personal teams on sign-up, and events for every membership change

Docs: https://packstub.dev/docs/filament-teams
