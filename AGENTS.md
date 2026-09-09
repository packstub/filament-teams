# packstub/filament-teams

Paid Filament v5 plugin: teams, invitations, seat limits and tenant-scoped roles for panels with tenancy. Paid: **Filament Teams** on packstub.dev, **Packstub Teams** on filamentphp.com.

## Commands

```bash
composer test               # Pest suite (Feature/, Feature/Spatie/)
composer test:filter <name>
composer lint               # Pint
```

## Layout

- `src/Teams.php` — the domain service (invite/accept/roles/seats/ownership); everything else calls it.
- `src/Roles/` — `RoleProvider` seam: `SimpleRoleProvider` (config roles on the pivot) and `SpatieRoleProvider` (Spatie Permission teams feature, i.e. what Filament Shield manages). Auto-selected when `permission.teams` is on.
- `src/Filament/` — `Members` and `Invitations` pages, `InviteMemberAction`; `src/Http/` — accept route + `SetPermissionsTeam` tenant middleware.
- `tests/Feature/Spatie/` runs on `SpatieTestCase` (teams enabled before boot); everything else on `TestCase`.
- `docs/` customer docs.

## Conventions

- Every change needs a test and a `CHANGELOG.md` line.
- The membership pivot is the source of truth for roles; providers only mirror. `owner` is reserved and never assignable through the UI (use `transferOwnership`).
- The user-side relation is `joinedTeams()` — Spatie's `HasRoles` owns `teams()`.
