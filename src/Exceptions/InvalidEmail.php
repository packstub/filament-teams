<?php

namespace Packstub\Teams\Exceptions;

class InvalidEmail extends TeamsException
{
    public static function make(): static
    {
        return new static(__('packstub-teams::teams.errors.invalid_email'));
    }
}
