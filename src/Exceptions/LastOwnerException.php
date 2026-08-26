<?php

namespace Packstub\Teams\Exceptions;

class LastOwnerException extends TeamsException
{
    public static function make(): static
    {
        return new static(__('packstub-teams::teams.errors.last_owner'));
    }
}
