<?php

namespace Packstub\Teams\Roles;

use Illuminate\Database\Eloquent\Model;

/**
 * Lists the roles a member may hold in a team and mirrors membership role
 * changes into an authorization backend. The membership pivot stays the
 * source of truth for "who has which role"; the provider decides what the
 * role *means* (e.g. a Spatie role carrying Shield permissions).
 */
interface RoleProvider
{
    /**
     * @return array<string, string> role => label
     */
    public function roles(Model $team): array;

    public function assigned(Model $user, Model $team, string $role): void;

    public function removed(Model $user, Model $team): void;
}
