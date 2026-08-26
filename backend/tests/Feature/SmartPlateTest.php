<?php

namespace Tests\Feature;

use App\Models\Meal;
use App\Models\NutritionGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SmartPlateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-26 13:00:00');

        // Smart Plate must never reach a provider. Any outbound HTTP is a bug,
        // so make one impossible to miss.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures                                                            */
    /* ------------------------------------------------------------------ */

    /** 2,000 kcal / 140 g protein / 220 g carbs / 65 g fat. */
    private function userWithGoals(): User
    {
        $user = User::factory()->create();

        NutritionGoal::factory()->for($user)->create([
            'calorie_target' => 2000,
            'protein_target' => 140,
            'carb_target' => 220,
            'fat_target' => 65,
        ]);

        return $user;
    }

    /** 1,420 kcal, 72 g protein, 180 g carbs, 45 g fat already eaten today. */
    private function withTodayLogged(User $user): User
    {
        Meal::factory()->for($user)->on('2026-08-26', 8)
            ->withTotals(700, 40, 90, 25)->create(['meal_name' => 'Porridge']);
        Meal::factory()->for($user)->on('2026-08-26', 12)
            ->withTotals(720, 32, 90, 20)->create(['meal_name' => 'Chicken Wrap']);

        return $user;
    }

    /**
     * One food item in the shape the review screen holds it: an AI estimate
     * with a baseline, so portions can be rescaled.
     *
     * @return array<string, mixed>
     */
    private function item(
        string $name,
        float $portion,
        float $calories,
        float $protein,
        float $carbs,
        float $fat,
        string $unit = 'g',
        ?array $locked = null,
        ?float $confidence = 0.86,
        bool $baseline = true,
    ): array {
        return [
            'name' => $name,
            'portion_amount' => $portion,
            'portion_unit' => $unit,
            'calories' => $calories,
            'protein' => $protein,
            'carbs' => $carbs,
            'fat' => $fat,
            'base_portion_amount' => $baseline ? $portion : null,
            'base_calories' => $baseline ? $calories : null,
            'base_protein' => $baseline ? $protein : null,
            'base_carbs' => $baseline ? $carbs : null,
            'base_fat' => $baseline ? $fat : null,
            'confidence' => $confidence,
            'locked_macros' => $locked ?? [],
        ];
    }

    /** @param list<array<string, mixed>> $items */
    private function analyse(User $user, array $items, array $extra = []): TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals/smart-plate', ['items' => $items] + $extra);
    }

    /**
     * Replay an optimization's changes the way the **frontend** does, from the
     * rules documented in `lib/meal-draft.ts` rather than by calling the
     * backend's own simulator. Scaling from the baseline, locked macros left
     * alone, calories whole and grams to one decimal.
     *
     * This is the cross-check that matters: if the two implementations ever
     * drift, a promised "+31 g protein" stops being true the moment the user
     * taps Apply.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $changes
     * @return list<array<string, mixed>>
     */
    private function applyChanges(array $items, array $changes): array
    {
        foreach ($changes as $change) {
            if ($change['action'] === 'add_item') {
                $items[] = $this->item(
                    $change['item_name'],
                    (float) $change['portion_amount'],
                    (float) $change['macros']['calories'],
                    (float) $change['macros']['protein'],
                    (float) $change['macros']['carbs'],
                    (float) $change['macros']['fat'],
                    unit: $change['portion_unit'],
                    confidence: null,
                );

                continue;
            }

            $index = $change['item_index'];
            $item = $items[$index];

            $this->assertSame(
                $item['name'],
                $change['item_name'],
                'A change must name the item it points at, so a stale index cannot be applied silently.',
            );

            $item['portion_amount'] = (float) $change['to_portion'];

            if ($item['base_portion_amount'] > 0) {
                $ratio = $item['portion_amount'] / $item['base_portion_amount'];

                foreach (['calories', 'protein', 'carbs', 'fat'] as $macro) {
                    if (in_array($macro, $item['locked_macros'], true)) {
                        continue;
                    }

                    $base = $item["base_{$macro}"];

                    if ($base === null) {
                        continue;
                    }

                    $item[$macro] = $macro === 'calories'
                        ? round($base * $ratio)
                        : round($base * $ratio, 1);
                }
            }

            $items[$index] = $item;
        }

        return $items;
    }

    /* ------------------------------------------------------------------ */
    /* Access control                                                      */
    /* ------------------------------------------------------------------ */

    public function test_smart_plate_requires_authentication(): void
    {
        $this->postJson('/api/meals/smart-plate', ['items' => []])->assertUnauthorized();
    }

    public function test_another_users_meal_cannot_be_named_as_the_meal_being_edited(): void
    {
        $owner = $this->withTodayLogged($this->userWithGoals());
        $intruder = $this->userWithGoals();

        $theirMeal = $owner->meals()->first();

        $this->analyse(
            $intruder,
            [$this->item('Rice', 200, 260, 5.4, 56.4, 0.6)],
            ['meal_id' => $theirMeal->id],
        )->assertNotFound();
    }

    public function test_the_analysis_only_ever_reflects_the_callers_own_day(): void
    {
        $user = $this->userWithGoals();

        // A different account has eaten a great deal today.
        $other = $this->withTodayLogged($this->userWithGoals());
        Meal::factory()->for($other)->on('2026-08-26')->withTotals(1800, 90, 200, 70)->create();

        $response = $this->analyse($user, [
            $this->item('Grilled chicken breast', 150, 248, 46.5, 0, 5.4),
        ])->assertOk();

        // Nothing logged on *this* account, so the full day is still open.
        $response->assertJsonPath('data.day.is_first_meal_today', true)
            ->assertJsonPath('data.day.consumed.calories', 0)
            ->assertJsonPath('data.day.remaining.calories', 2000);
    }

    /* ------------------------------------------------------------------ */
    /* The day                                                             */
    /* ------------------------------------------------------------------ */

    public function test_it_reports_the_real_remaining_macros(): void
    {
        $user = $this->withTodayLogged($this->userWithGoals());

        $this->analyse($user, [
            $this->item('Grilled chicken breast', 150, 248, 46.5, 0, 5.4),
            $this->item('White rice', 200, 260, 5.4, 56.4, 0.6),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.meal.calories', 508)
            ->assertJsonPath('data.meal.protein', 51.9)
            ->assertJsonPath('data.day.consumed.calories', 1420)
            ->assertJsonPath('data.day.remaining.calories', 580)
            ->assertJsonPath('data.day.remaining.protein', 68)
            ->assertJsonPath('data.day.remaining_after_meal.calories', 72)
            ->assertJsonPath('data.day.is_first_meal_today', false)
            ->assertJsonPath('data.day.meals_logged_today', 2);
    }

    public function test_the_first_meal_of_the_day_is_compared_against_the_full_targets(): void
    {
        $user = $this->userWithGoals();

        $response = $this->analyse($user, [
            $this->item('Porridge', 250, 178, 6.3, 30, 3.8),
        ])->assertOk();

        $response->assertJsonPath('data.day.is_first_meal_today', true)
            ->assertJsonPath('data.day.remaining.calories', 2000)
            ->assertJsonPath('data.day.remaining.protein', 140);

        $this->assertStringContainsString(
            'first meal today',
            $response->json('data.summary'),
        );
    }

    public function test_a_user_with_no_goals_gets_a_useful_state_rather_than_a_score(): void
    {
        $user = User::factory()->create();

        $this->analyse($user, [$this->item('Rice', 200, 260, 5.4, 56.4, 0.6)])
            ->assertOk()
            ->assertJsonPath('data.status', 'no_goals')
            ->assertJsonPath('data.meal_fit_score', null)
            ->assertJsonPath('data.optimizations', [])
            ->assertJsonPath('data.meal.calories', 260)
            ->assertJsonPath(
                'data.message',
                'Set your nutrition goals to unlock personalized meal optimization.',
            );
    }

    public function test_an_empty_draft_is_not_scored_from_zeroes(): void
    {
        $user = $this->userWithGoals();

        $this->analyse($user, [])
            ->assertOk()
            ->assertJsonPath('data.status', 'empty_meal')
            ->assertJsonPath('data.meal_fit_score', null);

        // A half-typed row is the same situation, not a validation failure.
        $this->analyse($user, [$this->item('', 100, 0, 0, 0, 0)])
            ->assertOk()
            ->assertJsonPath('data.status', 'empty_meal');
    }

    public function test_editing_a_saved_meal_does_not_count_it_twice(): void
    {
        $user = $this->withTodayLogged($this->userWithGoals());
        $meal = $user->meals()->where('meal_name', 'Chicken Wrap')->sole();

        // Without the exclusion, "remaining" would be 580 kcal. With it, the
        // 720 kcal wrap comes back out of the day it is already in.
        $this->analyse(
            $user,
            [$this->item('Chicken wrap', 250, 720, 32, 90, 20)],
            ['meal_id' => $meal->id],
        )
            ->assertOk()
            ->assertJsonPath('data.day.consumed.calories', 700)
            ->assertJsonPath('data.day.remaining.calories', 1300)
            ->assertJsonPath('data.day.meals_logged_today', 1);
    }

    /* ------------------------------------------------------------------ */
    /* The score                                                           */
    /* ------------------------------------------------------------------ */

    public function test_the_score_is_deterministic(): void
    {
        $user = $this->withTodayLogged($this->userWithGoals());
        $items = [$this->item('Grilled chicken breast', 150, 248, 46.5, 0, 5.4)];

        $first = $this->analyse($user, $items)->assertOk()->json('data.meal_fit_score');
        $second = $this->analyse($user, $items)->assertOk()->json('data.meal_fit_score');

        $this->assertSame($first, $second);
        $this->assertIsNumeric($first);
    }

    public function test_a_well_matched_high_protein_meal_scores_highly(): void
    {
        $user = $this->withTodayLogged($this->userWithGoals());

        // 580 kcal and 68 g of protein left; this covers both.
        $response = $this->analyse($user, [
            $this->item('Grilled chicken breast', 220, 363, 68.2, 0, 7.9),
            $this->item('Steamed broccoli', 150, 53, 3.6, 10.8, 0.6),
        ])->assertOk();

        $this->assertGreaterThanOrEqual(9.0, $response->json('data.meal_fit_score'));
        $response->assertJsonPath('data.rating', 'excellent_fit')
            ->assertJsonPath('data.breakdown.protein.status', 'excellent')
            ->assertJsonPath('data.breakdown.calories.status', 'good');
    }

    public function test_a_low_protein_meal_is_marked_low_and_offered_a_boost(): void
    {
        $user = $this->withTodayLogged($this->userWithGoals());

        $response = $this->analyse($user, [
            $this->item('White rice', 250, 325, 6.8, 70.5, 0.8),
        ])->assertOk();

        $response->assertJsonPath('data.breakdown.protein.status', 'needs_attention');

        $boost = $this->optimization($response, 'boost_protein');
        $this->assertTrue($boost['applicable']);
        $this->assertGreaterThan($boost['current_score'], $boost['new_score']);
        $this->assertGreaterThan(0, $boost['macro_difference']['protein']);
    }

    public function test_a_high_calorie_meal_is_flagged_and_offered_a_reduction(): void
    {
        $user = $this->withTodayLogged($this->userWithGoals());

        // 1,100 kcal against 580 remaining.
        $response = $this->analyse($user, [
            $this->item('Grilled chicken breast', 150, 248, 46.5, 0, 5.4),
            $this->item('White rice', 400, 520, 10.8, 112.8, 1.2),
            $this->item('Olive oil', 40, 354, 0, 0, 40),
        ])->assertOk();

        $calories = $response->json('data.breakdown.calories');
        $this->assertContains($calories['status'], ['high', 'needs_attention']);

        $reduce = $this->optimization($response, 'reduce_calories');
        $this->assertTrue($reduce['applicable']);
        $this->assertLessThan(0, $reduce['macro_difference']['calories']);
        $this->assertGreaterThan($reduce['current_score'], $reduce['new_score']);
    }

    public function test_a_meal_inside_the_calorie_budget_is_not_told_to_shrink(): void
    {
        $user = $this->withTodayLogged($this->userWithGoals());

        $response = $this->analyse($user, [
            $this->item('Grilled chicken breast', 150, 248, 46.5, 0, 5.4),
        ])->assertOk();

        $reduce = $this->optimization($response, 'reduce_calories');

        $this->assertFalse(
            $reduce['applicable'],
            'Nothing needs trimming, so no trim should be proposed.',
        );
        $this->assertStringContainsString('already fits', $reduce['unavailable_reason']);
    }

    public function test_a_carb_heavy_meal_reports_carbs_over_what_is_left(): void
    {
        $user = $this->withTodayLogged($this->userWithGoals());

        // 40 g of carbs left; this brings 90 g.
        $response = $this->analyse($user, [
            $this->item('White rice', 320, 416, 8.6, 90.2, 1),
        ])->assertOk();

        $carbs = $response->json('data.breakdown.carbs');
        $this->assertContains($carbs['status'], ['high', 'needs_attention']);
        $this->assertStringContainsString('over the', $carbs['message']);
    }

    public function test_a_fat_heavy_meal_reports_fat_over_what_is_left(): void
    {
        $user = $this->withTodayLogged($this->userWithGoals());

        // 20 g of fat left; this brings 45 g.
        $response = $this->analyse($user, [
            $this->item('Cheddar', 100, 403, 24.9, 1.3, 33.1),
            $this->item('Wholemeal bread', 60, 148, 7.8, 24.6, 2),
            $this->item('Butter', 12, 86, 0.1, 0, 9.7),
        ])->assertOk();

        $fat = $response->json('data.breakdown.fat');
        $this->assertContains($fat['status'], ['high', 'needs_attention']);
    }

    public function test_a_balanced_meal_is_told_it_is_balanced(): void
    {
        $user = $this->userWithGoals();

        // First meal of the day: a third of everything is a good breakfast.
        $response = $this->analyse($user, [
            $this->item('Greek yoghurt', 300, 219, 30, 11.4, 6),
            $this->item('Oats', 60, 227, 7.9, 40.6, 3.9),
            $this->item('Berries', 100, 57, 0.7, 14.5, 0.3),
        ])->assertOk();

        $this->assertGreaterThanOrEqual(9.0, $response->json('data.meal_fit_score'));

        $balance = $this->optimization($response, 'balance_meal');
        $this->assertFalse($balance['applicable']);
        $this->assertStringContainsString('already well balanced', $balance['unavailable_reason']);
    }

    /* ------------------------------------------------------------------ */
    /* Optimizations                                                       */
    /* ------------------------------------------------------------------ */

    public function test_all_three_optimizations_are_always_returned_in_a_stable_order(): void
    {
        $user = $this->withTodayLogged($this->userWithGoals());

        $ids = collect(
            $this->analyse($user, [$this->item('White rice', 250, 325, 6.8, 70.5, 0.8)])
                ->assertOk()
                ->json('data.optimizations')
        )->pluck('id')->all();

        $this->assertSame(['boost_protein', 'reduce_calories', 'balance_meal'], $ids);
    }

    /**
     * The invariant the whole feature rests on: applying a suggestion the way
     * the *client* does must land on exactly the macros and the score the
     * backend promised.
     */
    public function test_applying_a_suggestion_lands_on_the_promised_macros_and_score(): void
    {
        $user = $this->withTodayLogged($this->userWithGoals());

        $items = [
            $this->item('Grilled chicken breast', 120, 198, 37.2, 0, 4.3),
            $this->item('White rice', 300, 390, 8.1, 84.6, 0.9),
        ];

        $response = $this->analyse($user, $items)->assertOk();

        foreach ($response->json('data.optimizations') as $optimization) {
            if (! $optimization['applicable']) {
                continue;
            }

            $applied = $this->applyChanges($items, $optimization['changes']);
            $after = $this->analyse($user, $applied)->assertOk();

            $this->assertSame(
                $optimization['projected_meal'],
                $after->json('data.meal'),
                "Projected macros for {$optimization['id']} did not survive being applied.",
            );

            $this->assertSame(
                $optimization['new_score'],
                $after->json('data.meal_fit_score'),
                "Projected score for {$optimization['id']} did not survive being applied.",
            );
        }
    }

    public function test_suggestions_are_recalculated_from_the_new_meal_state(): void
    {
        $user = $this->withTodayLogged($this->userWithGoals());

        $items = [$this->item('White rice', 250, 325, 6.8, 70.5, 0.8)];

        $first = $this->analyse($user, $items)->assertOk();
        $boost = $this->optimization($first, 'boost_protein');

        $applied = $this->applyChanges($items, $boost['changes']);
        $second = $this->analyse($user, $applied)->assertOk();

        // The score moved, and the follow-up analysis describes the new plate
        // rather than repeating the old advice.
        $this->assertGreaterThan(
            $first->json('data.meal_fit_score'),
            $second->json('data.meal_fit_score'),
        );

        $secondBoost = $this->optimization($second, 'boost_protein');

        if ($secondBoost['applicable']) {
            $this->assertNotSame($boost['description'], $secondBoost['description']);
        } else {
            $this->assertNotNull($secondBoost['unavailable_reason']);
        }
    }

    public function test_a_change_names_the_item_it_points_at(): void
    {
        $user = $this->withTodayLogged($this->userWithGoals());

        $items = [
            $this->item('Grilled chicken breast', 150, 248, 46.5, 0, 5.4),
            $this->item('White rice', 400, 520, 10.8, 112.8, 1.2),
        ];

        foreach ($this->analyse($user, $items)->assertOk()->json('data.optimizations') as $optimization) {
            foreach ($optimization['changes'] as $change) {
                if ($change['action'] !== 'set_portion') {
                    continue;
                }

                // The index and the name have to agree, so the client can
                // refuse a suggestion built against an older meal state.
                $this->assertSame(
                    $items[$change['item_index']]['name'],
                    $change['item_name'],
                );
            }
        }
    }

    public function test_a_hand_entered_meal_cannot_be_rescaled_and_says_so(): void
    {
        $user = $this->withTodayLogged($this->userWithGoals());

        // No baseline: a manual item has no reference point to scale from.
        $response = $this->analyse($user, [
            $this->item('Leftovers', 1, 900, 20, 100, 35, unit: 'plate', confidence: null, baseline: false),
        ])->assertOk();

        $reduce = $this->optimization($response, 'reduce_calories');

        $this->assertFalse($reduce['applicable']);
        $this->assertStringContainsString('entered by hand', $reduce['unavailable_reason']);
    }

    public function test_a_locked_macro_is_never_assumed_to_move(): void
    {
        $user = $this->withTodayLogged($this->userWithGoals());

        // Calories typed by hand on the rice: a portion change must not claim
        // to reduce them.
        $items = [
            $this->item('Grilled chicken breast', 150, 248, 46.5, 0, 5.4),
            $this->item('White rice', 400, 520, 10.8, 112.8, 1.2, locked: ['calories']),
            $this->item('Olive oil', 30, 265, 0, 0, 30),
        ];

        $response = $this->analyse($user, $items)->assertOk();

        foreach ($response->json('data.optimizations') as $optimization) {
            if (! $optimization['applicable']) {
                continue;
            }

            foreach ($optimization['changes'] as $change) {
                if ($change['action'] !== 'set_portion') {
                    continue;
                }

                if ($change['item_name'] !== 'White rice') {
                    continue;
                }

                // If the rice is touched at all, the user must be told why its
                // calories will not move.
                $this->assertNotEmpty(
                    $optimization['notes'],
                    'A change to an item with a locked macro must explain itself.',
                );
                $this->assertStringContainsString('by hand', $optimization['notes'][0]);
            }
        }

        // And the promised numbers still hold once applied.
        foreach ($response->json('data.optimizations') as $optimization) {
            if (! $optimization['applicable']) {
                continue;
            }

            $applied = $this->applyChanges($items, $optimization['changes']);

            $this->assertSame(
                $optimization['projected_meal'],
                $this->analyse($user, $applied)->assertOk()->json('data.meal'),
            );
        }
    }

    public function test_an_item_with_every_macro_locked_is_never_proposed_for_rescaling(): void
    {
        $user = $this->withTodayLogged($this->userWithGoals());

        $items = [
            $this->item(
                'Fully edited plate',
                300,
                900,
                20,
                100,
                40,
                locked: ['calories', 'protein', 'carbs', 'fat'],
            ),
        ];

        foreach ($this->analyse($user, $items)->assertOk()->json('data.optimizations') as $optimization) {
            foreach ($optimization['changes'] as $change) {
                $this->assertNotSame(
                    'set_portion',
                    $change['action'],
                    'Rescaling an item whose every macro is locked would change nothing.',
                );
            }
        }
    }

    /**
     * With calories pinned by hand, growing a portion looks free to the scorer
     * — it would add protein at no visible cost and cheerfully propose 880 g of
     * rice. Eating more of something is never free, so an increase is only
     * offered where the price is counted.
     */
    public function test_an_item_with_locked_calories_is_never_proposed_for_an_increase(): void
    {
        $user = $this->withTodayLogged($this->userWithGoals());

        $items = [
            $this->item('Grilled chicken breast', 120, 198, 37.2, 0, 4.3),
            $this->item('White rice', 400, 520, 10.8, 112.8, 1.2, locked: ['calories']),
            $this->item('Olive oil', 25, 221, 0, 0, 25),
        ];

        foreach ($this->analyse($user, $items)->assertOk()->json('data.optimizations') as $optimization) {
            foreach ($optimization['changes'] as $change) {
                if ($change['action'] !== 'set_portion') {
                    continue;
                }

                if ($change['item_name'] !== 'White rice') {
                    continue;
                }

                $this->assertLessThan(
                    $change['from_portion'],
                    $change['to_portion'],
                    'An item with locked calories may be trimmed, never grown.',
                );
            }
        }
    }

    /**
     * The one place the score is overridden. Adding chicken to a meal that is
     * already hundreds of calories past the day wins on points and is still bad
     * advice.
     */
    public function test_nothing_is_added_to_a_meal_that_is_already_over_budget(): void
    {
        $user = $this->withTodayLogged($this->userWithGoals());

        // 580 kcal left; this is 1,100.
        $items = [
            $this->item(
                'Takeaway pizza',
                1,
                1100,
                42,
                120,
                48,
                unit: 'plate',
                confidence: null,
                baseline: false,
            ),
        ];

        $response = $this->analyse($user, $items)->assertOk();

        $boost = $this->optimization($response, 'boost_protein');
        $this->assertFalse($boost['applicable']);
        $this->assertStringContainsString('past what is left', $boost['unavailable_reason']);

        foreach ($response->json('data.optimizations') as $optimization) {
            foreach ($optimization['changes'] as $change) {
                $this->assertNotSame(
                    'add_item',
                    $change['action'],
                    'An over-budget meal must never be told to add more food.',
                );
            }
        }
    }

    public function test_an_over_budget_meal_can_still_be_trimmed(): void
    {
        $user = $this->withTodayLogged($this->userWithGoals());

        $response = $this->analyse($user, [
            $this->item('Grilled chicken breast', 150, 248, 46.5, 0, 5.4),
            $this->item('White rice', 400, 520, 10.8, 112.8, 1.2),
            $this->item('Olive oil', 30, 265, 0, 0, 30),
        ])->assertOk();

        $reduce = $this->optimization($response, 'reduce_calories');

        $this->assertTrue($reduce['applicable']);
        $this->assertLessThan(0, $reduce['macro_difference']['calories']);
    }

    public function test_a_suggestion_never_proposes_an_unrealistic_portion(): void
    {
        $user = $this->withTodayLogged($this->userWithGoals());

        $items = [$this->item('White rice', 200, 260, 5.4, 56.4, 0.6)];

        foreach ($this->analyse($user, $items)->assertOk()->json('data.optimizations') as $optimization) {
            foreach ($optimization['changes'] as $change) {
                if ($change['action'] === 'add_item') {
                    $this->assertLessThanOrEqual(250, $change['portion_amount']);
                    $this->assertGreaterThanOrEqual(20, $change['portion_amount']);

                    continue;
                }

                $from = $items[$change['item_index']]['portion_amount'];
                $this->assertLessThanOrEqual($from * 1.6, $change['to_portion']);
                $this->assertGreaterThanOrEqual($from * 0.4, $change['to_portion']);
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Uncertainty and validation                                          */
    /* ------------------------------------------------------------------ */

    /**
     * A low-confidence analysis is scored exactly like any other.
     *
     * Smart Plate deliberately says nothing about per-item confidence: the meal
     * review screen exists to check and correct these numbers, and a chip
     * reading "Estimated 31%" on every row was noise that told nobody what to
     * do. The one honest disclaimer on the screen above covers it.
     */
    public function test_a_low_confidence_analysis_is_scored_without_extra_commentary(): void
    {
        $user = $this->withTodayLogged($this->userWithGoals());

        $response = $this->analyse($user, [
            $this->item('Mystery stew', 300, 450, 20, 40, 18, confidence: 0.31),
        ])->assertOk();

        $response->assertJsonPath('data.status', 'ok')
            ->assertJsonMissingPath('data.confidence_notice');

        $this->assertIsNumeric($response->json('data.meal_fit_score'));

        // The same plate at high confidence scores identically: confidence is
        // carried, but it is not an input to the score.
        $this->assertSame(
            $response->json('data.meal_fit_score'),
            $this->analyse($user, [
                $this->item('Mystery stew', 300, 450, 20, 40, 18, confidence: 0.95),
            ])->assertOk()->json('data.meal_fit_score'),
        );
    }

    public function test_invalid_meal_values_are_rejected(): void
    {
        $user = $this->userWithGoals();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals/smart-plate', [
                'items' => [[
                    'name' => 'Negative food',
                    'portion_amount' => -5,
                    'portion_unit' => 'g',
                    'calories' => -100,
                    'protein' => 0,
                    'carbs' => 0,
                    'fat' => 0,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.portion_amount', 'items.0.calories']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals/smart-plate', [
                'items' => [[
                    'name' => 'Bad lock',
                    'portion_amount' => 100,
                    'portion_unit' => 'g',
                    'calories' => 100,
                    'protein' => 1,
                    'carbs' => 1,
                    'fat' => 1,
                    'locked_macros' => ['sodium'],
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.locked_macros.0');
    }

    public function test_the_response_never_carries_another_accounts_details(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
        NutritionGoal::factory()->for($user)->create();

        $body = $this->analyse($user, [$this->item('Rice', 200, 260, 5.4, 56.4, 0.6)])
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('ada@example.test', $body);
        $this->assertStringNotContainsString('Lovelace', $body);
        $this->assertStringNotContainsString($user->password, $body);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function optimization(TestResponse $response, string $id): array
    {
        foreach ($response->json('data.optimizations') as $optimization) {
            if ($optimization['id'] === $id) {
                return $optimization;
            }
        }

        $this->fail("No optimization with id {$id} in the response.");
    }
}
