# Invitations

## E-mail invitations

Managers invite one or many addresses with a role. Each address gets a `TeamInvitation` (one pending per e-mail per team — re-inviting refreshes it), a `TeamInvitationMail`, and, if they already have an account, a database notification in Filament's tray.

In the **Invite** modal, Enter, a space, a comma or a semicolon turns what you typed into an address, and so does leaving the field (Tab or a click elsewhere). A pasted list is split on spaces, commas, semicolons and tabs, so a row copied from a spreadsheet works; a column (one address per line) doesn't, because a single-line field drops the line breaks, so paste those one at a time or join them with commas first. A typo stops the form and names the address to fix, so nothing is sent until every address is valid. Addresses are matched without regard to case: `Jane@Acme.test` and `jane@acme.test` are the same person, whether she is already a member or already invited. When the team runs out of seats part-way through a batch, the addresses that fit are invited and the rest are reported.

Inviting an address that already has a pending invitation re-sends it with the new role, a fresh link and a new expiry date. The re-send waits for `invitations.resend_cooldown_minutes`, like **Resend**: inside the cooldown that address is reported ("This invitation was just sent. Try again 4 minutes from now.") and its invitation is left as it was. `Teams::invite(..., send: false)` updates the pending invitation without mailing and skips the cooldown; it keeps the link already e-mailed, so that link still works, and gives it a new expiry date. To fix a wrong role inside the cooldown from the panel, **Revoke** the invitation on the Invitations page, then invite the address again. `Teams::invite()` throws `InvalidEmail` for an address that isn't one, before anything is saved.

<img src="https://raw.githubusercontent.com/packstub/art/main/filament-teams/docs/invite-members.png" alt="Invite member modal with several addresses and a role">

```php
Teams::invite($team, 'jane@acme.test', 'editor', inviter: $user);
Teams::inviteMany($team, ['jane@acme.test', 'sam@acme.test'], 'viewer', $user);
Teams::inviteMany($team, 'jane@acme.test, sam@acme.test', 'viewer', $user); // a pasted list works too
// ['invitations' => [...], 'errors' => ['bad-address' => 'That is not a valid e-mail address.']]
```

Invitations expire after `invitations.expire_after_days`, can be re-sent after `invitations.resend_cooldown_minutes`, and can be revoked. An expired invitation no longer holds a seat, so re-sending it needs a free one. The **Invitations** page lists what's pending with a copy-link modal.

Addresses are compared without regard to case. On MySQL, MariaDB and SQL Server the default collations already do that, so the lookup uses the `email` index as is. On PostgreSQL and SQLite the lookup is `lower(email) = ?`; with a large users table on PostgreSQL, add an expression index so it stays fast:

```php
DB::statement('create index users_email_lower_index on users (lower(email))');
```

If your MySQL or MariaDB users table uses a case-sensitive collation (`utf8mb4_bin` or a `*_cs` one), store e-mail addresses lower-cased, for example with a mutator on the `User` model, so the lookup finds them.

<img src="https://raw.githubusercontent.com/packstub/art/main/filament-teams/docs/invitations.png" alt="Invitations page with a pending e-mail invitation and a shareable link">

## Accepting

The link hits `/{panel}/invitations/{token}`:

- **Guests** are sent to the panel's login — or its registration page — with the intended URL kept. Use `Packstub\Teams\Filament\Pages\Auth\Register` as the registration page and the invited e-mail is prefilled and locked.
- **On login or registration** the invitation from the link is accepted (`AcceptPendingInvitations` listener) and the route sends them to the team's dashboard with a welcome notification.
- With `require_email_match` on (default) only the invited address may accept an e-mail invitation.
- `invitations.auto_accept_on_login` accepts every other pending invitation addressed to that e-mail as well.

<img src="https://raw.githubusercontent.com/packstub/art/main/filament-teams/docs/register-invited.png" alt="Registration page with the invited e-mail prefilled and locked">

## Shareable links

A link anyone may use to join with a fixed role — until it expires or its uses run out. Links never hold a seat.

<img src="https://raw.githubusercontent.com/packstub/art/main/filament-teams/docs/create-link.png" alt="Create link modal">

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
