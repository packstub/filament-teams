<?php

namespace Packstub\Teams\Exceptions;

class NotAMember extends TeamsException
{
    public static function make(): static
    {
        return new static(__('packstub-teams::teams.errors.not_a_member'));
    }
}
