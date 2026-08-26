<?php

namespace Packstub\Teams\Exceptions;

class NoPermission extends TeamsException
{
    public static function make(): static
    {
        return new static(__('packstub-teams::teams.errors.no_permission'));
    }
}
