# Changelog

All notable changes to `packstub/filament-teams` are documented here.

## 1.0.0 — 2026-08-26

Initial release.

### Added

- `HasTeamMembers` (team/tenant model) and `HasTeamMemberships` (user model, satisfies Filament's `HasTenants`) traits over a configurable membership pivot — reuse `packstub/filament-tenancy`'s `tenant_user` out of the box.
- E-mail invitations: `TeamInvitation` model, `TeamInvitationMail`, panel accept route (`/{panel}/invitations/{token}`) that sends guests through login/registration and back, expiry, resend, revoke, e-mail matching.
- `Members` page (invite, change role, remove, transfer ownership, leave) and `Invitations` page (pending list, resend, revoke, copy link); `InviteMemberAction` for your own pages; tenant-menu entry.
- Seat limits from config or `TeamsPlugin::seatLimit()`; pending invitations count toward seats.
- Ownership rules: reserved owner role, last-owner protection, `transferOwnership()`.
- Role providers: `SimpleRoleProvider` (config roles) and `SpatieRoleProvider` (Spatie Permission teams — the roles Filament Shield manages), auto-selected; `SetPermissionsTeam` tenant middleware; `SpatieRoleProvider::cloneGlobalRolesToTeam()` to copy template roles into a new team; `Teams::defaultRoleFor()` fallback when the configured default role is not offered.
- Events: `MemberInvited`, `InvitationAccepted`, `MemberAdded`, `MemberRoleChanged`, `MemberRemoved`, `OwnershipTransferred`.
