<?php

namespace Packstub\Teams\Exceptions;

use Illuminate\Support\Carbon;

class ResendTooSoon extends TeamsException
{
    public static function until(Carbon $at): static
    {
        return new static(__('packstub-teams::teams.errors.resend_too_soon', ['time' => $at->diffForHumans()]));
    }
}
