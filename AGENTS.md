# packstub/filament-teams

Paid Filament v5 plugin: teams, invitations, seat limits and tenant-scoped roles for panels with tenancy. Sold on packstub.dev as **Filament Teams**; listed on filamentphp.com as **Packstub Teams** (the directory forbids "Filament" in names).

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
- `docs/` customer docs (synced to the store on release). Demo app: `../../demos/filament-teams-demo` (Shield + Spatie teams wired, browser E2E tests).

## Conventions

- PHP 8.3+, Pint, Pest; every change needs a test. Keep `CHANGELOG.md` current.
- Release = a `## <version>` heading in `CHANGELOG.md`, then a `v<version>` tag on `main`. `.github/workflows/github-release.yml` creates the GitHub release from that changelog section (manual run with a `tag` input for backfills) — no manual release step.
- The membership pivot is the source of truth for roles; providers only mirror. `owner` is reserved and never assignable through the UI (use `transferOwnership`).
- The user-side relation is `joinedTeams()` — Spatie's `HasRoles` owns `teams()`.
