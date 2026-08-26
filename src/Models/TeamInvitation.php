<?php

namespace Packstub\Teams\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Packstub\Teams\Facades\Teams;

/**
 * @property int $id
 * @property string $email
 * @property string $role
 * @property string $token
 * @property Carbon|null $expires_at
 * @property Carbon|null $accepted_at
 */
class TeamInvitation extends Model
{
    protected $guarded = [];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return config('packstub-teams.invitations_table', 'team_invitations');
    }

    public function getConnectionName(): ?string
    {
        return config('packstub-teams.connection') ?? parent::getConnectionName();
    }

    protected static function booted(): void
    {
        static::creating(function (self $invitation): void {
            $invitation->token ??= static::generateToken();
            $invitation->email = Str::lower(trim($invitation->email));
        });
    }

    public static function generateToken(): string
    {
        return Str::random(48);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(config('packstub-teams.team_model'), 'team_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'invited_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function acceptUrl(?string $panel = null): string
    {
        return Teams::acceptUrl($this, $panel);
    }

    public function regenerateToken(): static
    {
        $this->forceFill([
            'token' => static::generateToken(),
            'expires_at' => Teams::invitationExpiry(),
        ])->save();

        return $this;
    }
}
