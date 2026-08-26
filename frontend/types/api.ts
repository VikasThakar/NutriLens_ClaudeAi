/**
 * Wire types mirroring the Laravel API resources in
 * backend/app/Http/Resources.
 */

export type GoalType =
  | "lose_weight"
  | "maintain_weight"
  | "build_muscle"
  | "improve_nutrition";

export type MealType = "breakfast" | "lunch" | "dinner" | "snack";
export type MealSource = "ai_photo" | "manual";
export type MealStatus = "draft" | "logged";

/** How a goal's targets were arrived at. */
export type GoalSource = "onboarding" | "manual" | "calculator";

export type ActivityLevel =
  | "sedentary"
  | "light"
  | "moderate"
  | "active"
  | "very_active";

export type BiologicalSex = "female" | "male" | "unspecified";

/** Macro fields a user can lock by editing them by hand. */
export type MacroField = "calories" | "protein" | "carbs" | "fat";

export interface NutritionGoal {
  id: number;
  goal_type: GoalType;
  goal_label: string;
  calorie_target: number;
  protein_target: number;
  carb_target: number;
  fat_target: number;
  source: GoalSource | null;
  source_label: string | null;
  /** Set only when these targets came from the calculator. */
  estimated_maintenance_calories: number | null;
  is_active: boolean;
  effective_from: string | null;
  created_at?: string | null;
  updated_at: string | null;
}

export interface User {
  id: number;
  name: string;
  email: string;
  avatar_url: string | null;
  timezone: string | null;
  has_onboarded: boolean;
  onboarded_at: string | null;
  created_at: string | null;
  /** Present only when the backend eager-loaded the active goal. */
  nutrition_goal?: NutritionGoal | null;
}

export interface MacroTotals {
  calories: number;
  protein: number;
  carbs: number;
  fat: number;
}

export interface MealItem {
  id: number;
  name: string;
  brand: string | null;
  portion_amount: number;
  portion_unit: string;
  calories: number;
  protein: number;
  carbs: number;
  fat: number;
  fiber: number | null;
  sugar: number | null;
  sodium: number | null;
  /** The AI's original estimate, used as the base for portion scaling. */
  base_portion_amount: number | null;
  base_calories: number | null;
  base_protein: number | null;
  base_carbs: number | null;
  base_fat: number | null;
  confidence: number | null;
  is_ai_generated: boolean;
  was_edited: boolean;
  locked_macros: MacroField[];
  position: number;
}

export interface Meal {
  id: number;
  meal_name: string;
  meal_type: MealType;
  source: MealSource;
  status: MealStatus;
  consumed_at: string | null;
  consumed_on: string | null;
  totals: MacroTotals;
  ai_confidence: number | null;
  ai_provider: string | null;
  ai_model: string | null;
  notes: string | null;
  /** Short-lived signed URL, or null for a meal with no photo. */
  image_url: string | null;
  item_count?: number;
  items?: MealItem[];
}

/** One meal-type bucket on the Today dashboard. */
export interface MealGroup {
  meal_type: MealType;
  label: string;
  meal_count: number;
  totals: MacroTotals;
  meals: Meal[];
}

export interface TodaySummary {
  date: string;
  goal: NutritionGoal | null;
  consumed: MacroTotals;
  remaining: MacroTotals | null;
  meal_count: number;
  meals: Meal[];
  groups: MealGroup[];
  /** Always relative to the user's actual today, not the requested date. */
  streak: StreakSummary;
  trend: DailyNutritionPoint[];
  recent_meals: Meal[];
  latest_insight: WeeklyInsight | null;
  /** False only for an account that has never logged anything. */
  has_any_meals: boolean;
}

/* ---------------------------------------------------------------------------
   History, analytics and streaks
   --------------------------------------------------------------------------- */

/** One calendar day of totals. `logged: false` means nothing was recorded. */
export interface DailyNutritionPoint {
  date: string;
  calories: number;
  protein: number;
  carbs: number;
  fat: number;
  meals: number;
  logged: boolean;
  /** Present only on weekly buckets in a long analytics range. */
  days_logged?: number;
}

export interface StreakSummary {
  current: number;
  longest: number;
  logged_today: boolean;
  total_days_logged: number;
  last_logged_on: string | null;
  recent: { date: string; logged: boolean }[];
}

export interface HistoryDay {
  date: string;
  is_today: boolean;
  is_future: boolean;
  goal: NutritionGoal | null;
  totals: MacroTotals;
  remaining: MacroTotals | null;
  meal_count: number;
  meals: Meal[];
  /** Nearest day either side that actually has meals, for skipping gaps. */
  previous_logged_date: string | null;
  next_logged_date: string | null;
}

export interface HistoryCalendar {
  month: string;
  days: DailyNutritionPoint[];
  days_logged: number;
  total_meals: number;
}

export type AnalyticsRange = "week" | "month" | "quarter" | "year";

export interface TargetAdherence {
  days_close_to_target: number;
  days_logged: number;
  tolerance_percent: number;
  calorie_target: number | null;
  /** Null when there is no target, or nothing logged, to measure against. */
  percent: number | null;
}

export interface AnalyticsReport {
  range: {
    from: string;
    to: string;
    days: number;
    /** Long ranges are bucketed by week so the chart stays readable. */
    granularity: "day" | "week";
  };
  targets: MacroTotals | null;
  series: DailyNutritionPoint[];
  summary: {
    days_in_range: number;
    days_logged: number;
    total_meals: number;
    /** Averaged over logged days only — see `days_logged`. */
    averages: MacroTotals;
    totals: MacroTotals;
    target_adherence: TargetAdherence;
  };
}

/* ---------------------------------------------------------------------------
   Goal calculator
   --------------------------------------------------------------------------- */

export interface GoalCalculatorOptions {
  activity_levels: {
    value: ActivityLevel;
    label: string;
    description: string;
    multiplier: number;
  }[];
  biological_sexes: { value: BiologicalSex; label: string }[];
  goal_types: { value: GoalType; label: string }[];
  formula: string;
  saved_inputs: {
    age: number | null;
    height_cm: number | null;
    weight_kg: number | null;
    activity_level: ActivityLevel | null;
    biological_sex: BiologicalSex | null;
  };
}

export interface CalculateGoalInput {
  age: number;
  height_cm: number;
  weight_kg: number;
  activity_level: ActivityLevel;
  goal_type: GoalType;
  biological_sex?: BiologicalSex | null;
}

export interface GoalEstimate {
  bmr: number;
  maintenance_calories: number;
  calorie_adjustment: number;
  targets: {
    calorie_target: number;
    protein_target: number;
    carb_target: number;
    fat_target: number;
  };
  macro_percent: { protein: number; carbs: number; fat: number };
  protein_per_kg: number;
  goal_type: GoalType;
  goal_label: string;
  activity_level: ActivityLevel;
  activity_label: string;
  activity_multiplier: number;
  biological_sex: BiologicalSex;
  sex_was_specified: boolean;
  formula: string;
  is_estimate: true;
}

/* ---------------------------------------------------------------------------
   Weekly AI insights
   --------------------------------------------------------------------------- */

export interface WeeklyInsightComparison {
  week_start: string;
  days_logged: number;
  meals_logged: number;
  averages: MacroTotals;
}

export interface WeeklyInsight {
  id: number;
  week_start: string | null;
  week_end: string | null;
  headline: string | null;
  summary: string | null;
  observations: string[];
  suggestions: string[];
  stats: {
    days_logged: number;
    meals_logged: number;
    avg_calories: number;
    avg_protein: number;
    avg_carbs: number;
    avg_fat: number;
    calorie_target: number | null;
    days_close_to_target: number;
    days_close_percent: number | null;
  };
  comparison: WeeklyInsightComparison | null;
  generated_at: string | null;
  ai_provider: string | null;
  ai_model: string | null;
}

/** The aggregated week, with no AI involved. */
export interface WeeklyAggregates {
  week_start: string;
  week_end: string;
  days_logged: number;
  meals_logged: number;
  averages: MacroTotals;
  targets: MacroTotals | null;
  days_close_to_target: number;
  tolerance_percent: number;
  days: {
    date: string;
    weekday: string;
    logged: boolean;
    calories: number;
    protein: number;
    carbs: number;
    fat: number;
    meals: number;
  }[];
  meals_by_type: Record<MealType, number>;
  weekday_average_calories: number | null;
  weekend_average_calories: number | null;
  calorie_spread: number | null;
  previous_week: WeeklyInsightComparison | null;
}

export interface CurrentWeekInsight {
  week_start: string;
  week_end: string;
  is_current_week: boolean;
  aggregates: WeeklyAggregates;
  insight: WeeklyInsight | null;
  /** True when the meals behind a stored summary have since changed. */
  is_stale: boolean;
  has_enough_data: boolean;
  requirement: { min_days_logged: number; days_logged: number };
}

/**
 * `insufficient_data` is an expected outcome, not a failure: the week does not
 * have enough logged days for a summary to say anything true.
 */
export type GenerateInsightResponse =
  | {
      status: "ok";
      message: string;
      reused: boolean;
      data: { insight: WeeklyInsight; aggregates: WeeklyAggregates };
    }
  | {
      status: "insufficient_data";
      message: string;
      data: {
        aggregates: WeeklyAggregates;
        requirement: { min_days_logged: number; days_logged: number };
      };
    };

/* ---------------------------------------------------------------------------
   AI analysis
   --------------------------------------------------------------------------- */

export interface AnalyzedItem {
  name: string;
  portion_amount: number;
  portion_unit: string;
  calories: number;
  protein: number;
  carbs: number;
  fat: number;
  confidence: number;
}

export interface AnalyzedMeal {
  meal_name: string;
  confidence: number;
  notes: string | null;
  items: AnalyzedItem[];
  totals: MacroTotals;
  provider: string;
  model: string;
}

/** The stored upload an analysis relates to. */
export interface MealImageRef {
  id: number;
  url: string;
  width: number | null;
  height: number | null;
}

export interface AnalyzeMealResponse {
  analysis: AnalyzedMeal;
  meal_image: MealImageRef;
}

/* ---------------------------------------------------------------------------
   Request / response envelopes
   --------------------------------------------------------------------------- */

export interface AuthPayload {
  user: User;
  token: string;
}

/** Laravel wraps single resources in `data`. */
export interface DataEnvelope<T> {
  data: T;
}

export interface MessageEnvelope<T> {
  message: string;
  data: T;
}

export interface PaginatedEnvelope<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface RegisterInput {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  device_name?: string;
}

export interface LoginInput {
  email: string;
  password: string;
  device_name?: string;
}

export interface NutritionGoalInput {
  goal_type: GoalType;
  calorie_target: number;
  protein_target: number;
  carb_target: number;
  fat_target: number;
  source?: GoalSource;
  estimated_maintenance_calories?: number | null;
}

export interface OnboardingInput {
  goal_type: GoalType;
  calorie_target?: number | null;
  protein_target?: number | null;
  carb_target?: number | null;
  fat_target?: number | null;
  /** Set only when the onboarding calculator produced these targets. */
  source?: GoalSource;
  estimated_maintenance_calories?: number | null;
}

export interface UpdateProfileInput {
  name?: string;
  email?: string;
  timezone?: string;
}

export interface MealItemInput {
  name: string;
  brand?: string | null;
  portion_amount: number;
  portion_unit: string;
  calories: number;
  protein: number;
  carbs: number;
  fat: number;
  base_portion_amount?: number | null;
  base_calories?: number | null;
  base_protein?: number | null;
  base_carbs?: number | null;
  base_fat?: number | null;
  confidence?: number | null;
  is_ai_generated?: boolean;
  was_edited?: boolean;
  locked_macros?: MacroField[];
}

export interface StoreMealInput {
  meal_name: string;
  meal_type: MealType;
  source?: MealSource;
  consumed_at?: string | null;
  notes?: string | null;
  ai_confidence?: number | null;
  ai_provider?: string | null;
  ai_model?: string | null;
  meal_image_id?: number | null;
  items: MealItemInput[];
}

export type UpdateMealInput = Partial<Omit<StoreMealInput, "items">> & {
  items?: MealItemInput[];
};

export interface MealListParams {
  date?: string;
  from?: string;
  to?: string;
  meal_type?: MealType;
  per_page?: number;
}

/** Shape of a Laravel 422 validation response. */
export type ValidationErrors = Record<string, string[]>;

/* ---------------------------------------------------------------------------
   Partner API keys
   --------------------------------------------------------------------------- */

export interface ApiKey {
  id: number;
  name: string;
  /** The leading characters of the key. The rest is unrecoverable. */
  key_prefix: string;
  abilities: string[];
  is_active: boolean;
  created_at: string | null;
  last_used_at: string | null;
  revoked_at: string | null;
  expires_at: string | null;
}

export interface ApiKeyListResponse {
  data: ApiKey[];
  meta: { active_count: number; max_active: number };
}

/**
 * The only response that ever carries the full key. It is not stored anywhere
 * on the client either — it lives in component state until the dialog closes.
 */
export interface CreatedApiKey {
  message: string;
  data: { key: ApiKey; plain_text_key: string };
}
