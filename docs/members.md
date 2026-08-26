# Members

Every member of the current tenant sees the **Members** page; managers (owners plus the roles in `roles.managers`) invite, change roles and remove members; owners can hand over ownership.

<img src="https://packstub.dev/images/docs/filament-teams/members.png" alt="Members page — roles per member with Change role, Transfer ownership and Remove actions">

## Roles

Each member holds exactly one role in each team. `owner` is reserved: it is never offered in the role picker (use *Transfer ownership*), and the last owner can neither be demoted nor removed.

<img src="https://packstub.dev/images/docs/filament-teams/change-role.png" alt="Change role modal">

```php
Teams::changeRole($team, $user, 'admin');
Teams::transferOwnership($team, from: $owner, to: $user);   // previous owner becomes an admin
Teams::removeMember($team, $user);
```

## Seats

A team's seat limit comes from config (`seat_limit`) or from the plugin — typically the plan:

```php
TeamsPlugin::make()->seatLimit(fn (Team $team): ?int => $team->plan?->seats); // null = unlimited
```

Members and pending e-mail invitations count toward the limit (`invitations.count_pending_toward_seats`); shareable links don't until they are used. The page subheading and the invite modal show *n of m seats in use*, and the Invite button is disabled when the team is full.

## Who may manage

By default owners and the `roles.managers` roles. Replace the rule per panel:

```php
TeamsPlugin::make()
    ->canManageUsing(fn (User $user, Team $team): bool => $user->hasTeamPermission($team, 'members.manage'))
    ->canInviteUsing(fn (User $user, Team $team): bool => $user->hasTeamPermission($team, 'members.invite'));
```

## Leaving

Members leave from the page header; an owner can leave once another owner exists.

## Events

`MemberAdded`, `MemberRoleChanged`, `MemberRemoved`, `OwnershipTransferred` — each carries the team and the user models.
