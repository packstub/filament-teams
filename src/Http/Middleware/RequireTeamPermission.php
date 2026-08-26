<?php

namespace Packstub\Teams\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Packstub\Teams\Teams;

/**
 * `->middleware('team.can:update')` — 403 unless the signed-in user holds
 * the permission in the current Filament tenant.
 */
class RequireTeamPermission
{
    public function __construct(protected Teams $teams) {}

    public function handle(Request $request, Closure $next, string $permission): mixed
    {
        $user = Filament::auth()->user() ?? $request->user();
        $team = Filament::getTenant();

        abort_unless($user && $team && $this->teams->userCan($user, $team, $permission), 403);

        return $next($request);
    }
}
