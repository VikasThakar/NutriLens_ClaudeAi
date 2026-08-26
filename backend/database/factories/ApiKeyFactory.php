<?php

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiKey>
 *
 * Produces a key row with an unguessable digest. Tests that need to *use* a key
 * go through ApiKeyService::create() instead, because only that returns the
 * plaintext.
 */
class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true).' integration',
            'key_prefix' => 'nl_live_'.fake()->unique()->lexify('??????'),
            'key_hash' => hash('sha256', fake()->unique()->uuid()),
            'abilities' => ['nutrition:analyze', 'nutrition:estimate'],
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()->subDay()]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }
}
