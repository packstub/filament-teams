# Security policy

Filament Teams decides who belongs to a team and what they may do there: it sends and accepts invitations, assigns roles, protects the owner role and counts seats. Please report anything in that area privately so a fix can ship before details become public.

## Supported versions

Security fixes are released for the latest minor version of each supported release line.

| Version | Supported |
| ------- | --------- |
| 1.x     | Yes       |

## Reporting a vulnerability

Email **security@packstub.dev**. Do not open a public GitHub issue, discussion, or pull request for a security problem.

Include what you can: the package version (`composer show packstub/filament-teams`), the Filament version, the role provider in use (`simple`, `spatie` with Filament Shield, or your own), whether the panel runs on Filament Tenancy, and a minimal reproduction.

What to expect: acknowledgment within 48 hours from a human, updates while we validate and fix, and coordinated disclosure with credit in the release notes unless you prefer otherwise.

## Scope

Highest priority:

- **Membership**: joining a team without a valid invitation, accepting an e-mail invitation addressed to someone else while `invitations.require_email_match` is on, or using an expired, revoked or used-up invitation or link.
- **Roles and ownership**: a member changing roles, removing members or inviting without being allowed to manage the team, anyone receiving the `owner` role other than through `transferOwnership()`, or a team left without an owner.
- **Team boundaries**: reading or changing the members, invitations or roles of a team the user does not belong to, including a Spatie Permission role or permission answering for the wrong team.
- **Seat limits**: adding members or pending invitations past a team's seat limit.

Also in scope: the Members and Invitations pages, the invite action, the registration page, the accept route, the `team.can` and `SetPermissionsTeam` middleware, the artisan commands, and the published config, views and migrations.

Out of scope: vulnerabilities in Laravel, Filament, Livewire, Spatie Permission or Filament Shield themselves (report upstream; we still ship a mitigation when the exposure is specific to this package), issues that need an already-compromised admin account or server, and missing hardening in your own application.

## Hardening guidance for integrators

- **Invitation links are credentials.** Anyone who signs in with a shareable link joins with the link's role, so give links a `maxUses` and an `expiresAt`, and keep `invitations.require_email_match` on for e-mail invitations.
- **The owner role is reserved.** Assign it only with `Teams::addMember($team, $user, Teams::ownerRole())` when a team is created and with `Teams::transferOwnership()` afterwards; never take a role name for these calls from request input.
- **Who may manage is yours to decide.** Owners and the `roles.managers` roles manage members by default. `canManageUsing()` and `canInviteUsing()` replace that rule, so keep them as strict as the pages they guard.
- **Permissions are checked where you ask.** `hasTeamPermission()`, `@teamcan` and the `team.can:` middleware answer for the team you pass or the current Filament tenant; your own routes, policies and queries still need to scope records to that team.
- **Prune old invitations.** Schedule `php artisan teams:prune-invitations` so expired and long-accepted invitations do not stay in the table.
