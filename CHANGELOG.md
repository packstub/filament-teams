# Changelog

All notable changes to `packstub/filament-teams` are documented here.

## Unreleased

- Run several panels on different team models: `TeamsPlugin` settings now apply per panel, and unset ones fall back to the config file
- **Upgrading:** seeders and tests that switch panels with `Filament::setCurrentPanel()` call `TeamsPlugin::get()->configure()` afterwards
- Docs: a shorter Features list in the README and on the docs index

## 1.0.0 — 2026-08-26

First release of Filament Teams: members, invitations, roles and seats for your Filament panel.

- Members and Invitations pages: invite, change roles, remove, transfer ownership, leave
- Email invitations, bulk invites and shareable links, accepted on sign-in or sign-up
- Seat limits, with pending invitations counting toward seats
- Roles from config or Spatie Permission (the roles Filament Shield manages), each with its permissions
- Uses Filament Tenancy's `tenant_user` pivot out of the box, or your own models without the traits
- Personal teams on sign-up, and events for every membership change

Docs: https://packstub.dev/docs/filament-teams
