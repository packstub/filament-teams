<?php

namespace Packstub\Teams\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Packstub\Teams\Roles\RoleCache;

/**
 * The team ⇄ user pivot. Table and column names come from config so an
 * existing pivot (e.g. packstub/filament-tenancy's `tenant_user`) can be reused.
 */
class Membership extends Pivot
{
    public $incrementing = true;

    /**
     * attach(), detach() and updateExistingPivot() on a relation that uses
     * this pivot fire these too, so writes that bypass Teams still drop the
     * remembered roles. The row carries ids only, not models, hence a flush.
     */
    protected static function booted(): void
    {
        static::saved(fn () => app(RoleCache::class)->flush());
        static::deleted(fn () => app(RoleCache::class)->flush());
    }

    public function getTable(): string
    {
        return config('packstub-teams.membership.table', 'team_user');
    }

    public function getConnectionName(): ?string
    {
        return config('packstub-teams.connection') ?? parent::getConnectionName();
    }

    public function getRole(): string
    {
        return (string) $this->getAttribute(config('packstub-teams.membership.role_column', 'role'));
    }
}
