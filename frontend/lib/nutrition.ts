import {
  Cookie,
  Croissant,
  Moon,
  Sun,
  type LucideIcon,
} from "lucide-react";

import type { GoalType, MacroKey } from "@/types";
import type { MealType } from "@/types/api";

/**
 * The macro palette is fixed and semantic: every ring, bar and chart in the
 * product uses the same colour for the same macro.
 */
export const MACRO_META: Record<
  MacroKey,
  { label: string; short: string; unit: string; cssVar: string; className: string }
> = {
  calories: {
    label: "Calories",
    short: "Cal",
    unit: "kcal",
    cssVar: "var(--macro-calories)",
    className: "text-calories",
  },
  protein: {
    label: "Protein",
    short: "Protein",
    unit: "g",
    cssVar: "var(--macro-protein)",
    className: "text-protein",
  },
  carbs: {
    label: "Carbohydrates",
    short: "Carbs",
    unit: "g",
    cssVar: "var(--macro-carbs)",
    className: "text-carbs",
  },
  fat: {
    label: "Fat",
    short: "Fat",
    unit: "g",
    cssVar: "var(--macro-fat)",
    className: "text-fat",
  },
};

export const GOAL_OPTIONS: {
  value: GoalType;
  label: string;
  description: string;
  targets: { calorie_target: number; protein_target: number; carb_target: number; fat_target: number };
}[] = [
  {
    value: "lose_weight",
    label: "Lose Weight",
    description: "A moderate deficit with protein kept high to protect muscle.",
    targets: { calorie_target: 1800, protein_target: 140, carb_target: 160, fat_target: 60 },
  },
  {
    value: "maintain_weight",
    label: "Maintain Weight",
    description: "Balanced macros that hold your current weight steady.",
    targets: { calorie_target: 2200, protein_target: 130, carb_target: 240, fat_target: 75 },
  },
  {
    value: "build_muscle",
    label: "Build Muscle",
    description: "A surplus with high protein to support training and recovery.",
    targets: { calorie_target: 2800, protein_target: 190, carb_target: 320, fat_target: 85 },
  },
  {
    value: "improve_nutrition",
    label: "Improve Nutrition",
    description: "Eat better without chasing a number on the scale.",
    targets: { calorie_target: 2000, protein_target: 120, carb_target: 220, fat_target: 70 },
  },
];

export function goalOption(goal: GoalType) {
  return GOAL_OPTIONS.find((option) => option.value === goal) ?? GOAL_OPTIONS[3];
}

export const MEAL_TYPES: {
  value: MealType;
  label: string;
  /** Plural form, used for dashboard group headings. */
  groupLabel: string;
  icon: LucideIcon;
}[] = [
  { value: "breakfast", label: "Breakfast", groupLabel: "Breakfast", icon: Croissant },
  { value: "lunch", label: "Lunch", groupLabel: "Lunch", icon: Sun },
  { value: "dinner", label: "Dinner", groupLabel: "Dinner", icon: Moon },
  { value: "snack", label: "Snack", groupLabel: "Snacks", icon: Cookie },
];

export function mealTypeMeta(mealType: MealType) {
  return MEAL_TYPES.find((option) => option.value === mealType) ?? MEAL_TYPES[3];
}

/**
 * The meal type most likely intended right now, so Add Meal opens on a sensible
 * default instead of making the user pick every time.
 */
export function suggestedMealType(date: Date): MealType {
  const hour = date.getHours();
  if (hour < 11) return "breakfast";
  if (hour < 15) return "lunch";
  if (hour < 21) return "dinner";
  return "snack";
}

export function formatMealTime(iso: string | null): string {
  if (!iso) return "";

  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "";

  return date.toLocaleTimeString("en-US", {
    hour: "numeric",
    minute: "2-digit",
  });
}

/** Clamped 0–100 percentage, safe when the target is 0 or missing. */
export function progressPercent(consumed: number, target: number): number {
  if (!target || target <= 0) return 0;
  return Math.min(100, Math.max(0, (consumed / target) * 100));
}

/** Uncapped percentage — used to detect going over a target. */
export function rawPercent(consumed: number, target: number): number {
  if (!target || target <= 0) return 0;
  return (consumed / target) * 100;
}

export function formatMacro(value: number, unit = "g"): string {
  const rounded = Math.round(value * 10) / 10;
  const display = Number.isInteger(rounded) ? rounded.toString() : rounded.toFixed(1);
  return `${display}${unit}`;
}

export function formatCalories(value: number): string {
  return Math.round(value).toLocaleString("en-US");
}

export function greetingFor(date: Date): string {
  const hour = date.getHours();
  if (hour < 5) return "Still up";
  if (hour < 12) return "Good morning";
  if (hour < 17) return "Good afternoon";
  return "Good evening";
}

export function firstName(fullName: string): string {
  return fullName.trim().split(/\s+/)[0] ?? fullName;
}