import { api } from "@/lib/api-client";
import type {
  CalculateGoalInput,
  DataEnvelope,
  GoalCalculatorOptions,
  GoalEstimate,
  MessageEnvelope,
  NutritionGoal,
  NutritionGoalInput,
  OnboardingInput,
  User,
} from "@/types/api";

export const goalsService = {
  /** Active goal, or null if the user has not set one yet. */
  current() {
    return api.get<DataEnvelope<NutritionGoal | null>>("/nutrition-goals");
  },

  update(input: NutritionGoalInput) {
    return api.put<MessageEnvelope<NutritionGoal>>("/nutrition-goals", input);
  },

  /** Every goal the user has had, newest first. */
  history() {
    return api.get<DataEnvelope<NutritionGoal[]>>("/nutrition-goals/history");
  },

  /**
   * The calculator's options, plus whatever the user entered last time so the
   * form arrives pre-filled.
   */
  calculatorOptions() {
    return api.get<DataEnvelope<GoalCalculatorOptions>>(
      "/nutrition-goals/calculator",
    );
  },

  /**
   * Returns an estimate only. Nothing about the active goal changes until the
   * user reviews the numbers and saves them with `update()`.
   */
  calculate(input: CalculateGoalInput) {
    return api.post<MessageEnvelope<GoalEstimate>>(
      "/nutrition-goals/calculate",
      input,
    );
  },

  /**
   * Completes onboarding: stores the chosen goal and (optionally) custom daily
   * targets, and flips the account's onboarded flag. Omit the targets to accept
   * the recommended defaults for the goal.
   */
  completeOnboarding(input: OnboardingInput) {
    return api.post<MessageEnvelope<User>>("/onboarding", input);
  },
};
