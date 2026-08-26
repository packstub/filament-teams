<?php

namespace Packstub\Teams\Tests\Fixtures;

use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Shaped like packstub/filament-tenancy's Tenant: string primary key, its
 * own `users()` pivot relation on `tenant_user`, no plugin trait.
 */
class TenantLikeTeam extends Model implements HasName
{
    protected $table = 'tenants';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn (self $tenant) => $tenant->id ??= (string) Str::uuid());
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(TenantLikeUser::class, 'tenant_user', 'tenant_id', 'user_id')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }
}
