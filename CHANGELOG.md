# Changelog

All notable changes to `packstub/filament-teams` are documented here.

## Unreleased

### Changed

- `TeamsPlugin` applies its settings per panel: `configure()` pushes the panel's config overrides, role provider, seat limit and callbacks into the runtime when the panel is registered *and* again when it boots, so an app can run several panels on different team models (a Tenant-backed panel next to a plain `Team` panel). Settings a panel leaves unset now fall back to the config file instead of what another panel applied. Seeders and tests that switch panels with `Filament::setCurrentPanel()` call `TeamsPlugin::get()->configure()` afterwards.
- **Docs**: a shorter Features list in the README and on the docs index, one line per area; the README's list gets its own `## Features` heading.

## 1.0.0 — 2026-08-26

Initial release.

### Added

- `HasTeamMembers` (team/tenant model) and `HasTeamMemberships` (user model, satisfies Filament's `HasTenants`) traits over a configurable membership pivot — reuse `packstub/filament-tenancy`'s `tenant_user` out of the box.
- E-mail invitations: `TeamInvitation` model, `TeamInvitationMail`, panel accept route (`/{panel}/invitations/{token}`) that sends guests through login/registration and back, expiry, resend, revoke, e-mail matching.
- `Members` page (invite, change role, remove, transfer ownership, leave) and `Invitations` page (pending list, resend, revoke, copy link); `InviteMemberAction` for your own pages; tenant-menu entry.
- Seat limits from config or `TeamsPlugin::seatLimit()`; pending invitations count toward seats.
- Ownership rules: reserved owner role, last-owner protection, `transferOwnership()`.
- Role providers: `SimpleRoleProvider` (config roles) and `SpatieRoleProvider` (Spatie Permission teams — the roles Filament Shield manages), auto-selected; `SetPermissionsTeam` tenant middleware; `SpatieRoleProvider::cloneGlobalRolesToTeam()` to copy template roles into a new team; `Teams::defaultRoleFor()` fallback when the configured default role is not offered.
- Works without the traits: `Teams::membersOf() / teamsOf() / invitationsOf() / ownersOf() / isMember() / roleOf() / seatsUsed() / hasAvailableSeats()` build relations from config; `membership.team_relationship` / `user_relationship` config; `HasTeamRoles` user trait for apps with another tenancy trait.
- String/UUID team keys in the migrations; `connection` config for central-database setups; `TeamsPlugin::useFilamentTenancy()` preset and `TeamsPlugin::config()` per-panel overrides.
- Role permissions: role definitions carry `permissions` (Jetstream style, `*` = all); `Teams::userCan()`, `hasTeamPermission()`, `team.can:` middleware, `@teamcan` Blade directive; `TeamsPlugin::roles()`.
- Invitations: bulk invites (`inviteMany()`, tags input in the UI), shareable links with use limits (`createInviteLink()`, "Create link" action), resend cooldown, in-app database notification for existing users, automatic acceptance on login/registration (`AcceptPendingInvitations`, optional `auto_accept_on_login`), `Pages\Auth\Register` that prefills/locks the invited e-mail, `teams:prune-invitations` command.
- Personal teams: `TeamsPlugin::personalTeams()` creates a team for every new registration that did not arrive through an invitation.
- Events: `MemberInvited`, `InvitationAccepted`, `MemberAdded`, `MemberRoleChanged`, `MemberRemoved`, `OwnershipTransferred`.
