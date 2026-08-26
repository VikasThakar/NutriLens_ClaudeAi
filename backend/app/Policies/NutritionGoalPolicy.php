<?php

namespace App\Policies;

use App\Models\NutritionGoal;
use App\Models\User;

class NutritionGoalPolicy
{
    public function view(User $user, NutritionGoal $goal): bool
    {
        return $user->id === $goal->user_id;
    }

    public function update(User $user, NutritionGoal $goal): bool
    {
        return $user->id === $goal->user_id;
    }

    public function delete(User $user, NutritionGoal $goal): bool
    {
        return $user->id === $goal->user_id;
    }
}