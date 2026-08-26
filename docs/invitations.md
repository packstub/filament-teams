# Invitations

## E-mail invitations

Managers invite one or many addresses with a role. Each address gets a `TeamInvitation` (one pending per e-mail per team — re-inviting refreshes it), a `TeamInvitationMail`, and, if they already have an account, a database notification in Filament's tray.

<img src="https://packstub.dev/images/docs/filament-teams/invite-members.png" alt="Invite member modal with several addresses and a role">

```php
Teams::invite($team, 'jane@acme.test', 'editor', inviter: $user);
Teams::inviteMany($team, ['jane@acme.test', 'sam@acme.test'], 'viewer', $user);
// ['invitations' => [...], 'errors' => ['bad-address' => 'That is not a valid e-mail address.']]
```

Invitations expire after `invitations.expire_after_days`, can be re-sent after `invitations.resend_cooldown_minutes`, and can be revoked. The **Invitations** page lists what's pending with a copy-link modal.

<img src="https://packstub.dev/images/docs/filament-teams/invitations.png" alt="Invitations page with a pending e-mail invitation and a shareable link">

## Accepting

The link hits `/{panel}/invitations/{token}`:

- **Guests** are sent to the panel's login — or its registration page — with the intended URL kept. Use `Packstub\Teams\Filament\Pages\Auth\Register` as the registration page and the invited e-mail is prefilled and locked.
- **On login or registration** the invitation from the link is accepted (`AcceptPendingInvitations` listener) and the route sends them to the team's dashboard with a welcome notification.
- With `require_email_match` on (default) only the invited address may accept an e-mail invitation.
- `invitations.auto_accept_on_login` accepts every other pending invitation addressed to that e-mail as well.

<img src="https://packstub.dev/images/docs/filament-teams/register-invited.png" alt="Registration page with the invited e-mail prefilled and locked">

## Shareable links

A link anyone may use to join with a fixed role — until it expires or its uses run out. Links never hold a seat.

<img src="https://packstub.dev/images/docs/filament-teams/create-link.png" alt="Create link modal">

```php
$link = Teams::createInviteLink($team, 'viewer', maxUses: 10, expiresAt: now()->addWeek(), inviter: $user);
$link->acceptUrl();
```

Disable link creation in the UI with `invitations.links => false`.

## Housekeeping

```bash
php artisan teams:prune-invitations   # expired ones + accepted ones older than prune_accepted_after_days
```

## Events

`MemberInvited` (e-mail or link), `InvitationAccepted`.

## Customising the e-mail

Publish the views (`php artisan vendor:publish --tag=packstub-teams-views`) and edit `mail/invitation.blade.php`; set `invitations.mail.queue` to send through your queue. Translations: `--tag=packstub-teams-translations`.
