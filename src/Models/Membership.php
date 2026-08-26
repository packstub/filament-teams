<?php

namespace Packstub\Teams\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The team ⇄ user pivot. Table and column names come from config so an
 * existing pivot (e.g. packstub/filament-tenancy's `tenant_user`) can be reused.
 */
class Membership extends Pivot
{
    public $incrementing = true;

    public function getTable(): string
    {
        return config('packstub-teams.membership.table', 'team_user');
    }

    public function getRole(): string
    {
        return (string) $this->getAttribute(config('packstub-teams.membership.role_column', 'role'));
    }
}
