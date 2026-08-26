<?php

namespace Tests\Feature;

use App\Models\Meal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StreakTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Every streak assertion depends on what "today" is, so the clock is
        // pinned rather than left to the machine running the suite.
        Carbon::setTestNow('2026-08-25 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function logOn(User $user, string ...$dates): void
    {
        foreach ($dates as $date) {
            Meal::factory()->for($user)->on($date)->create();
        }
    }

    public function test_the_streak_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/streak')->assertUnauthorized();
    }

    public function test_a_user_with_no_meals_has_no_streak(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/streak')
            ->assertOk()
            ->assertJsonPath('data.current', 0)
            ->assertJsonPath('data.longest', 0)
            ->assertJsonPath('data.logged_today', false)
            ->assertJsonPath('data.total_days_logged', 0)
            ->assertJsonPath('data.last_logged_on', null)
            ->assertJsonCount(14, 'data.recent');
    }

    public function test_several_meals_on_one_day_count_as_one_day(): void
    {
        $user = User::factory()->create();

        Meal::factory()->for($user)->on('2026-08-25', 8)->create();
        Meal::factory()->for($user)->on('2026-08-25', 13)->create();
        Meal::factory()->for($user)->on('2026-08-25', 19)->create();

        $this->actingAs($user)->getJson('/api/streak')
            ->assertOk()
            ->assertJsonPath('data.current', 1)
            ->assertJsonPath('data.longest', 1)
            ->assertJsonPath('data.total_days_logged', 1);
    }

    public function test_consecutive_days_build_the_current_streak(): void
    {
        $user = User::factory()->create();

        $this->logOn($user, '2026-08-23', '2026-08-24', '2026-08-25');

        $this->actingAs($user)->getJson('/api/streak')
            ->assertOk()
            ->assertJsonPath('data.current', 3)
            ->assertJsonPath('data.longest', 3)
            ->assertJsonPath('data.logged_today', true);
    }

    public function test_the_streak_survives_a_today_with_nothing_logged_yet(): void
    {
        $user = User::factory()->create();

        // Nothing today; the run ends yesterday. A streak should not read as
        // broken before the user has had a chance to eat.
        $this->logOn($user, '2026-08-22', '2026-08-23', '2026-08-24');

        $this->actingAs($user)->getJson('/api/streak')
            ->assertOk()
            ->assertJsonPath('data.current', 3)
            ->assertJsonPath('data.logged_today', false);
    }

    public function test_a_full_missed_day_breaks_the_current_streak(): void
    {
        $user = User::factory()->create();

        // Last logged two days ago: yesterday was missed entirely.
        $this->logOn($user, '2026-08-21', '2026-08-22', '2026-08-23');

        $this->actingAs($user)->getJson('/api/streak')
            ->assertOk()
            ->assertJsonPath('data.current', 0)
            ->assertJsonPath('data.longest', 3)
            ->assertJsonPath('data.last_logged_on', '2026-08-23');
    }

    public function test_the_longest_streak_is_kept_after_a_gap(): void
    {
        $user = User::factory()->create();

        // A five-day run in the past, then a gap, then two days ending today.
        $this->logOn(
            $user,
            '2026-08-10', '2026-08-11', '2026-08-12', '2026-08-13', '2026-08-14',
            '2026-08-24', '2026-08-25',
        );

        $this->actingAs($user)->getJson('/api/streak')
            ->assertOk()
            ->assertJsonPath('data.current', 2)
            ->assertJsonPath('data.longest', 5)
            ->assertJsonPath('data.total_days_logged', 7);
    }

    public function test_a_month_boundary_does_not_break_a_streak(): void
    {
        Carbon::setTestNow('2026-09-01 09:00:00');

        $user = User::factory()->create();

        $this->logOn($user, '2026-08-30', '2026-08-31', '2026-09-01');

        $this->actingAs($user)->getJson('/api/streak')
            ->assertOk()
            ->assertJsonPath('data.current', 3);
    }

    public function test_today_is_resolved_in_the_users_own_timezone(): void
    {
        // 20:00 UTC on the 25th is already 08:00 on the 26th in Auckland.
        Carbon::setTestNow('2026-08-25 20:00:00');

        $user = User::factory()->create(['timezone' => 'Pacific/Auckland']);

        $this->logOn($user, '2026-08-26');

        $this->actingAs($user)->getJson('/api/streak')
            ->assertOk()
            ->assertJsonPath('data.current', 1)
            ->assertJsonPath('data.logged_today', true);
    }

    public function test_recent_activity_covers_the_last_fortnight_ending_today(): void
    {
        $user = User::factory()->create();

        $this->logOn($user, '2026-08-25');

        $response = $this->actingAs($user)->getJson('/api/streak')->assertOk();

        $recent = $response->json('data.recent');

        $this->assertCount(14, $recent);
        $this->assertSame('2026-08-12', $recent[0]['date']);
        $this->assertSame('2026-08-25', $recent[13]['date']);
        $this->assertTrue($recent[13]['logged']);
        $this->assertFalse($recent[12]['logged']);
    }

    public function test_another_users_meals_never_count_toward_a_streak(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->logOn($other, '2026-08-23', '2026-08-24', '2026-08-25');

        $this->actingAs($user)->getJson('/api/streak')
            ->assertOk()
            ->assertJsonPath('data.current', 0)
            ->assertJsonPath('data.longest', 0);
    }

    public function test_a_soft_deleted_meal_no_longer_counts(): void
    {
        $user = User::factory()->create();

        $this->logOn($user, '2026-08-24', '2026-08-25');

        Meal::query()->whereDate('consumed_on', '2026-08-25')->firstOrFail()->delete();

        $this->actingAs($user)->getJson('/api/streak')
            ->assertOk()
            ->assertJsonPath('data.current', 1)
            ->assertJsonPath('data.logged_today', false);
    }
}
