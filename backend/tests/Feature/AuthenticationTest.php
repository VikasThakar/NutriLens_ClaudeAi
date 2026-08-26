<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Alex Morgan',
            'email' => 'alex@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.email', 'alex@example.com')
            ->assertJsonPath('data.user.has_onboarded', false)
            ->assertJsonStructure(['message', 'data' => ['user' => ['id', 'name', 'email'], 'token']]);

        $this->assertDatabaseHas('users', ['email' => 'alex@example.com']);

        // The password must never be stored in plain text.
        $user = User::firstWhere('email', 'alex@example.com');
        $this->assertNotSame('Password123', $user->password);
        $this->assertTrue(Hash::check('Password123', $user->password));
    }

    public function test_registration_normalises_the_email_and_rejects_duplicates(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/register', [
            'name' => 'Alex Morgan',
            'email' => 'TAKEN@Example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_registration_requires_all_fields(): void
    {
        $this->postJson('/api/register', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_registration_rejects_a_weak_password_and_a_mismatched_confirmation(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Alex Morgan',
            'email' => 'alex@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/register', [
            'name' => 'Alex Morgan',
            'email' => 'alex2@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password124',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_a_user_can_log_in_with_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'alex@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'alex@example.com',
            'password' => 'Password123',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'alex@example.com')
            ->assertJsonStructure(['data' => ['token']]);
    }

    public function test_login_fails_with_an_invalid_password(): void
    {
        User::factory()->create([
            'email' => 'alex@example.com',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'alex@example.com',
            'password' => 'WrongPassword1',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_login_does_not_reveal_whether_an_email_exists(): void
    {
        $unknown = $this->postJson('/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'Password123',
        ]);

        User::factory()->create(['email' => 'alex@example.com', 'password' => 'Password123']);

        $wrongPassword = $this->postJson('/api/login', [
            'email' => 'alex@example.com',
            'password' => 'WrongPassword1',
        ]);

        $this->assertSame(
            $unknown->json('errors.email'),
            $wrongPassword->json('errors.email'),
        );
    }

    public function test_the_current_user_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_an_authenticated_user_can_read_their_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email)
            // The password hash must never be serialised.
            ->assertJsonMissingPath('data.password');
    }

    public function test_logging_out_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create([
            'email' => 'alex@example.com',
            'password' => 'Password123',
        ]);

        $phoneToken = $user->createToken('phone');
        $laptopToken = $user->createToken('laptop');

        $this->withHeader('Authorization', "Bearer {$laptopToken->plainTextToken}")
            ->postJson('/api/logout')
            ->assertOk();

        // The laptop's token row is gone; the phone's survives.
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $laptopToken->accessToken->id,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $phoneToken->accessToken->id,
        ]);

        // Laravel's RequestGuard memoises the resolved user for the lifetime of
        // the test, so it has to be cleared before asserting on a later request.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$laptopToken->plainTextToken}")
            ->getJson('/api/user')
            ->assertUnauthorized();

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$phoneToken->plainTextToken}")
            ->getJson('/api/user')
            ->assertOk();
    }

    public function test_a_user_can_update_their_name(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/user', ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        $this->assertSame('New Name', $user->fresh()->name);
    }
}
