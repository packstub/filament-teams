<?php

namespace Packstub\Teams\Tests\Fixtures;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Packstub\Teams\Concerns\HasTeamMemberships;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    use HasRoles;
    use HasTeamMemberships;

    protected $guarded = [];

    protected $hidden = ['password'];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
