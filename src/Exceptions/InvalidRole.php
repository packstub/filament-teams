<?php

namespace Packstub\Teams\Exceptions;

class InvalidRole extends TeamsException
{
    public static function make(): static
    {
        return new static(__('packstub-teams::teams.errors.invalid_role'));
    }
}
