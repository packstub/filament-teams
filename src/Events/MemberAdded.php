<?php

namespace Packstub\Teams\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

class MemberAdded
{
    use Dispatchable;

    public function __construct(
        public readonly Model $team,
        public readonly Model $user,
        public readonly string $role,
    ) {}
}
