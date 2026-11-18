<?php

namespace Packstub\Teams\Roles;

use Illuminate\Database\Eloquent\Model;

/**
 * Remembers each member's role for one request (or one queued job), so
 * permission checks on a page ask the pivot once per user and team.
 *
 * Bound as a scoped instance: the container drops it between Octane
 * requests and between queue jobs, so nothing outlives the unit of work it
 * was filled in. Teams forgets entries when it changes a membership, and
 * the Membership pivot forgets on saved / deleted.
 */
class RoleCache
{
    /**
     * @var array<string, array{team: string, user: string, role: ?string}>
     */
    protected array $entries = [];

    /**
     * @param  callable(): ?string  $resolve
     */
    public function remember(Model $team, Model $user, callable $resolve): ?string
    {
        $key = $this->key($team, $user);

        if (array_key_exists($key, $this->entries)) {
            return $this->entries[$key]['role'];
        }

        $role = $resolve();

        $this->entries[$key] = ['team' => $this->ref($team), 'user' => $this->ref($user), 'role' => $role];

        return $role;
    }

    public function forget(Model $team, Model $user): void
    {
        $team = $this->ref($team);
        $user = $this->ref($user);

        $this->forgetWhere(fn (array $entry): bool => $entry['team'] === $team && $entry['user'] === $user);
    }

    public function forgetTeam(Model $team): void
    {
        $team = $this->ref($team);

        $this->forgetWhere(fn (array $entry): bool => $entry['team'] === $team);
    }

    public function forgetUser(Model $user): void
    {
        $user = $this->ref($user);

        $this->forgetWhere(fn (array $entry): bool => $entry['user'] === $user);
    }

    public function flush(): void
    {
        $this->entries = [];
    }

    /**
     * The membership settings are part of the key: panels may point Teams at
     * different pivots (TeamsPlugin::configure()), and a role read through one
     * must not answer for another.
     */
    protected function key(Model $team, Model $user): string
    {
        $config = (array) config('packstub-teams.membership', []);

        return implode('|', [
            (string) config('packstub-teams.connection'),
            (string) ($config['table'] ?? ''),
            (string) ($config['role_column'] ?? ''),
            $this->ref($team),
            $this->ref($user),
        ]);
    }

    protected function ref(Model $model): string
    {
        return $model->getMorphClass().'#'.$model->getKey();
    }

    /**
     * @param  callable(array{team: string, user: string, role: ?string}): bool  $matches
     */
    protected function forgetWhere(callable $matches): void
    {
        $this->entries = array_filter($this->entries, fn (array $entry): bool => ! $matches($entry));
    }
}
