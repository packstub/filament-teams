<?php

namespace Packstub\Teams\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Packstub\Teams\Models\TeamInvitation;

class MemberInvited
{
    use Dispatchable;

    public function __construct(
        public readonly TeamInvitation $invitation,
    ) {}
}
