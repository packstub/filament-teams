<?php

return [
    'members' => [
        'title' => 'Members',
    ],
    'invitations' => [
        'title' => 'Invitations',
        'empty_heading' => 'No pending invitations',
        'empty_description' => 'Invite someone and their invitation will show up here until they accept it.',
        'never_expires' => 'Never',
        'link_help' => 'Share this link with the person you invited. Anyone signed in with their e-mail address can use it to join.',
    ],
    'fields' => [
        'name' => 'Name',
        'email' => 'E-mail address',
        'role' => 'Role',
        'joined' => 'Joined',
        'invited_by' => 'Invited by',
        'expires' => 'Expires',
    ],
    'actions' => [
        'invite' => 'Invite member',
        'change_role' => 'Change role',
        'transfer_ownership' => 'Transfer ownership',
        'remove' => 'Remove',
        'leave' => 'Leave team',
        'copy_link' => 'Invitation link',
        'resend' => 'Resend',
        'revoke' => 'Revoke',
        'close' => 'Close',
    ],
    'confirm' => [
        'transfer_ownership' => ':name becomes the owner and you keep managing the team as an admin.',
        'remove' => ':name loses access to this team immediately.',
        'leave' => 'You lose access to this team. An owner can invite you again later.',
    ],
    'seats' => [
        'summary' => ':used of :limit seats in use',
    ],
    'notifications' => [
        'invited' => 'Invitation sent to :email',
        'resent' => 'Invitation sent again',
        'revoked' => 'Invitation revoked',
        'role_changed' => 'Role updated',
        'ownership_transferred' => 'Ownership transferred',
        'removed' => 'Member removed',
        'joined' => 'Welcome to :team',
        'invitation_invalid' => 'This invitation link is no longer valid.',
    ],
    'errors' => [
        'seat_limit_reached' => 'The team has no available seats.',
        'last_owner' => 'A team must keep at least one owner.',
        'invalid_invitation' => 'This invitation is no longer valid.',
        'invalid_role' => 'That role is not available for this team.',
        'not_a_member' => 'That user is not a member of this team.',
        'already_a_member' => 'That user is already a member of this team.',
    ],
    'mail' => [
        'subject' => 'You have been invited to join :team',
        'heading' => 'Join :team',
        'intro_by' => ':inviter invited you to join :team as :role.',
        'intro' => 'You have been invited to join :team as :role.',
        'button' => 'Accept invitation',
        'expires' => 'This invitation expires on :date.',
        'ignore' => 'If you were not expecting this invitation, you can safely ignore this e-mail.',
    ],
];
