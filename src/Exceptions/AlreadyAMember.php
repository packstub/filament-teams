<?php

namespace Packstub\Teams\Exceptions;

class AlreadyAMember extends TeamsException
{
    public static function make(): static
    {
        return new static(__('packstub-teams::teams.errors.already_a_member'));
    }
}
