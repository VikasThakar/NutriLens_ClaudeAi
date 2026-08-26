<?php

namespace App\Policies;

use App\Models\Meal;
use App\Models\User;

/**
 * Meals are strictly private to their owner. Controllers additionally scope
 * every query through $user->meals(); this policy is the second line of
 * defence for any route that resolves a meal by id.
 */
class MealPolicy
{
    public function view(User $user, Meal $meal): bool
    {
        return $user->id === $meal->user_id;
    }

    public function update(User $user, Meal $meal): bool
    {
        return $user->id === $meal->user_id;
    }

    public function delete(User $user, Meal $meal): bool
    {
        return $user->id === $meal->user_id;
    }
}