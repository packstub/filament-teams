<?php

namespace Packstub\Teams\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

/**
 * Points Spatie Permission's team context at the current Filament tenant so
 * `$user->can()` and Shield's policies resolve the roles of *this* team.
 */
class SetPermissionsTeam
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (($tenant = Filament::getTenant()) && class_exists(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

            $user = Filament::auth()->user();

            if ($user && method_exists($user, 'unsetRelation')) {
                $user->unsetRelation('roles')->unsetRelation('permissions');
            }
        }

        return $next($request);
    }
}
