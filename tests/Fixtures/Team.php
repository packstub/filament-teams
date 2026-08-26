<?php

namespace Packstub\Teams\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Packstub\Teams\Concerns\HasTeamMembers;

class Team extends Model
{
    use HasTeamMembers;

    protected $guarded = [];
}
