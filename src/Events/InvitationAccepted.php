<?php

namespace Packstub\Teams\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Packstub\Teams\Models\TeamInvitation;

class InvitationAccepted
{
    use Dispatchable;

    public function __construct(
        public readonly TeamInvitation $invitation,
        public readonly Model $user,
    ) {}
}
