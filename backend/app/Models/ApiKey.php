<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A partner API key.
 *
 * Only a digest of the key is ever stored — see ApiKeyService. `key_prefix`
 * holds the first few characters in clear so the owner can recognise which key
 * a row refers to without the secret being recoverable from it.
 */
class ApiKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'key_prefix',
        'key_hash',
        'abilities',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    /**
     * The hash must never leave the server, in any serialisation.
     *
     * @var list<string>
     */
    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /** Whether this key is allowed to perform a given partner operation. */
    public function can(string $ability): bool
    {
        $abilities = $this->abilities ?? [];

        return $abilities === [] || in_array($ability, $abilities, true);
    }

    /** @param Builder<ApiKey> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** @param Builder<ApiKey> $query */
    public function scopeNewestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
