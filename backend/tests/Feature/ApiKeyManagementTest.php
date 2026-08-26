<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_key_endpoints_require_authentication(): void
    {
        $this->getJson('/api/api-keys')->assertUnauthorized();
        $this->postJson('/api/api-keys', ['name' => 'Test'])->assertUnauthorized();
        $this->deleteJson('/api/api-keys/1')->assertUnauthorized();
    }

    public function test_a_new_user_has_no_keys(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/api-keys')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.active_count', 0)
            ->assertJsonPath('meta.max_active', 10);
    }

    public function test_creating_a_key_returns_the_plaintext_exactly_once(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/api-keys', ['name' => 'Acme staging'])
            ->assertCreated();

        $plain = $response->json('data.plain_text_key');

        $this->assertIsString($plain);
        $this->assertStringStartsWith('nl_live_', $plain);
        $this->assertSame(48, strlen($plain), 'The key should be the prefix plus 40 random characters.');

        $response->assertJsonPath('data.key.name', 'Acme staging')
            ->assertJsonPath('data.key.is_active', true)
            ->assertJsonPath('data.key.last_used_at', null);

        // The prefix shown in the UI is the leading characters of the real key.
        $this->assertStringStartsWith($response->json('data.key.key_prefix'), $plain);

        // Listing it again never exposes the secret.
        $listed = $this->actingAs($user)->getJson('/api/api-keys')->assertOk();

        $this->assertStringNotContainsString($plain, $listed->getContent());
        $listed->assertJsonMissingPath('data.0.plain_text_key')
            ->assertJsonMissingPath('data.0.key_hash');
    }

    public function test_the_raw_key_is_never_stored(): void
    {
        $user = User::factory()->create();

        $plain = $this->actingAs($user)
            ->postJson('/api/api-keys', ['name' => 'Storage check'])
            ->assertCreated()
            ->json('data.plain_text_key');

        $stored = ApiKey::query()->sole();

        // Neither the key nor its random half appears anywhere in the row.
        $this->assertNotSame($plain, $stored->key_hash);
        $this->assertSame(hash('sha256', $plain), $stored->key_hash);
        $this->assertStringNotContainsString(substr($plain, 8), (string) json_encode($stored->getAttributes()));

        // 64 hex characters — a SHA-256 digest, not the key.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $stored->key_hash);
    }

    public function test_two_keys_are_never_the_same(): void
    {
        $user = User::factory()->create();
        $keys = [];

        for ($i = 0; $i < 5; $i++) {
            $keys[] = $this->actingAs($user)
                ->postJson('/api/api-keys', ['name' => "Key {$i}"])
                ->assertCreated()
                ->json('data.plain_text_key');
        }

        $this->assertCount(5, array_unique($keys));
    }

    public function test_the_key_name_is_validated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/api-keys', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->actingAs($user)->postJson('/api/api-keys', ['name' => 'a'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->actingAs($user)->postJson('/api/api-keys', ['name' => str_repeat('a', 61)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        // Whitespace is trimmed before validation, so " " is not a valid name.
        $this->actingAs($user)->postJson('/api/api-keys', ['name' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_the_active_key_limit_is_enforced(): void
    {
        $user = User::factory()->create();
        ApiKey::factory()->for($user)->count(10)->create();

        $this->actingAs($user)->postJson('/api/api-keys', ['name' => 'One too many'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        // Revoking one frees a slot.
        ApiKey::query()->first()->forceFill(['revoked_at' => now()])->save();

        $this->actingAs($user)->postJson('/api/api-keys', ['name' => 'Now there is room'])
            ->assertCreated();
    }

    public function test_a_key_can_be_revoked(): void
    {
        $user = User::factory()->create();
        $key = ApiKey::factory()->for($user)->create();

        $this->actingAs($user)->deleteJson("/api/api-keys/{$key->id}")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertNotNull($key->fresh()->revoked_at);

        // Revoked, not deleted — the row survives for the audit trail.
        $this->actingAs($user)->getJson('/api/api-keys')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_active', false)
            ->assertJsonPath('meta.active_count', 0);
    }

    public function test_revoking_twice_is_harmless(): void
    {
        $user = User::factory()->create();
        $key = ApiKey::factory()->for($user)->create();

        $this->actingAs($user)->deleteJson("/api/api-keys/{$key->id}")->assertOk();
        $revokedAt = $key->fresh()->revoked_at;

        $this->actingAs($user)->deleteJson("/api/api-keys/{$key->id}")->assertOk();

        $this->assertEquals($revokedAt, $key->fresh()->revoked_at, 'The original revocation time should stand.');
    }

    public function test_the_list_is_scoped_to_the_caller(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        ApiKey::factory()->for($other)->count(3)->create();

        $this->actingAs($user)->getJson('/api/api-keys')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_user_cannot_revoke_another_users_key(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $key = ApiKey::factory()->for($owner)->create();

        $this->actingAs($intruder)->deleteJson("/api/api-keys/{$key->id}")
            ->assertForbidden();

        $this->assertNull($key->fresh()->revoked_at, 'The key must still be usable by its owner.');
    }

    public function test_keys_are_listed_newest_first(): void
    {
        $user = User::factory()->create();

        ApiKey::factory()->for($user)->create(['name' => 'Older', 'created_at' => now()->subDays(2)]);
        ApiKey::factory()->for($user)->create(['name' => 'Newer', 'created_at' => now()]);

        $this->actingAs($user)->getJson('/api/api-keys')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Newer')
            ->assertJsonPath('data.1.name', 'Older');
    }

    public function test_deleting_a_user_removes_their_keys(): void
    {
        $user = User::factory()->create();
        ApiKey::factory()->for($user)->count(2)->create();

        $user->delete();

        $this->assertDatabaseCount('api_keys', 0);
    }

    public function test_the_service_finds_a_key_only_by_its_exact_plaintext(): void
    {
        $user = User::factory()->create();
        /** @var ApiKeyService $service */
        $service = app(ApiKeyService::class);

        $created = $service->create($user, 'Lookup test');
        $plain = $created['plain_text'];

        $this->assertSame($created['key']->id, $service->find($plain)?->id);

        // Near misses resolve to nothing.
        $this->assertNull($service->find(substr($plain, 0, -1)));
        $this->assertNull($service->find($plain.'x'));
        $this->assertNull($service->find(strtoupper($plain)));
        $this->assertNull($service->find(''));
        $this->assertNull($service->find('some-other-token'));
    }
}
