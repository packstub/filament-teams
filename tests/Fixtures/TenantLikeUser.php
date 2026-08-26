<?php

namespace Packstub\Teams\Tests\Fixtures;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Packstub\Teams\Concerns\HasTeamRoles;

/**
 * A user whose tenancy methods come from elsewhere (HasPackstubTenants in
 * real apps) — only the role helpers are borrowed from this plugin.
 */
class TenantLikeUser extends Authenticatable implements FilamentUser, HasTenants
{
    use HasTeamRoles;

    protected $table = 'users';

    protected $guarded = [];

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(TenantLikeTeam::class, 'tenant_user', 'user_id', 'tenant_id')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function getTenants(Panel $panel): array|Collection
    {
        return $this->tenants;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->tenants->contains($tenant);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
