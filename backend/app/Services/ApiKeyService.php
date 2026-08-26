<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Issues and verifies partner API keys.
 *
 * ## Why SHA-256 and not bcrypt
 *
 * Password hashing is deliberately slow because passwords are low-entropy and
 * user-chosen. An API key here is 40 characters of CSPRNG output — brute-forcing
 * it is infeasible regardless of hash speed, and there is no dictionary to run
 * against it. What we *do* need is to find the key's row from the key itself on
 * every request, and a salted-and-slow hash cannot be looked up: it would mean
 * loading every key in the table and verifying one by one.
 *
 * So: a fast, deterministic digest over a high-entropy secret, stored behind a
 * unique index. The plaintext is never written to the database, never logged,
 * and returned exactly once — from `create()`, straight to the response.
 */
class ApiKeyService
{
    /** Marks the key's environment, so a leaked string is identifiable at a glance. */
    private const PREFIX = 'nl_live_';

    /**
     * Characters of randomness after the prefix. Each comes from a 32-symbol
     * alphabet, so 40 of them carry 200 bits.
     */
    private const SECRET_LENGTH = 40;

    /** How much of the key is stored in clear for display, e.g. `nl_live_a1b2c3`. */
    private const DISPLAY_LENGTH = 14;

    /**
     * Create a key for a user.
     *
     * @return array{key: ApiKey, plain_text: string} The plaintext is the only
     *                                                copy that will ever exist.
     */
    public function create(User $user, string $name): array
    {
        $plainText = self::PREFIX.$this->randomSecret();

        $key = $user->apiKeys()->create([
            'name' => $name,
            'key_prefix' => substr($plainText, 0, self::DISPLAY_LENGTH),
            'key_hash' => $this->hash($plainText),
            'abilities' => ['nutrition:analyze', 'nutrition:estimate'],
        ]);

        return ['key' => $key, 'plain_text' => $plainText];
    }

    /**
     * Resolve a presented key to its record, or null.
     *
     * Returns revoked and expired keys too — the caller decides how to respond,
     * because "this key was revoked" and "this key never existed" deserve
     * different messages to a legitimate partner and identical ones to an
     * attacker.
     */
    public function find(string $plainText): ?ApiKey
    {
        $plainText = trim($plainText);

        if ($plainText === '' || ! str_starts_with($plainText, self::PREFIX)) {
            return null;
        }

        return ApiKey::query()
            ->with('user')
            ->where('key_hash', $this->hash($plainText))
            ->first();
    }

    /**
     * Record that a key was used.
     *
     * Throttled to once a minute per key: the exact second of the last request
     * is not worth a write on every single call.
     */
    public function touch(ApiKey $key): void
    {
        if ($key->last_used_at !== null && $key->last_used_at->diffInSeconds(now()) < 60) {
            return;
        }

        $key->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    public function revoke(ApiKey $key): void
    {
        if ($key->revoked_at !== null) {
            return;
        }

        $key->forceFill(['revoked_at' => now()])->save();
    }

    private function hash(string $plainText): string
    {
        return hash('sha256', $plainText);
    }

    private function randomSecret(): string
    {
        // Base32-style alphabet: no padding, no case ambiguity, no characters
        // that are easy to confuse, and safe to double-click or put in a header.
        $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
        $bytes = random_bytes(self::SECRET_LENGTH);
        $secret = '';

        // 256 is an exact multiple of 32, so the modulo introduces no bias —
        // every symbol is produced by exactly eight byte values.
        foreach (str_split($bytes) as $byte) {
            $secret .= $alphabet[ord($byte) % 32];
        }

        return $secret;
    }
}
