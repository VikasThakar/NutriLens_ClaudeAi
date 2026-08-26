import {
  CircleAlert,
  CircleCheck,
  TrendingDown,
  TrendingUp,
  type LucideIcon,
} from "lucide-react";

import {
  draftItemFromSuggestion,
  setItemPortion,
  toNumber,
  type MealDraft,
} from "@/lib/meal-draft";
import type {
  MacroField,
  SmartPlateChange,
  SmartPlateInput,
  SmartPlateItemInput,
  SmartPlateMacroStatus,
  SmartPlateRating,
} from "@/types/api";

/**
 * Client-side glue for Smart Plate.
 *
 * The backend does the arithmetic; this file's only jobs are to describe the
 * draft accurately on the way out, and to apply a suggestion on the way back
 * *through the existing editing functions* rather than around them.
 */

/** How long to wait after an edit before re-analysing. */
export const SMART_PLATE_DEBOUNCE_MS = 700;

/* ---------------------------------------------------------------------------
   Request
   --------------------------------------------------------------------------- */

/**
 * The draft as the analyser needs it.
 *
 * The baseline and lock fields are as important as the macros: they are what
 * let the backend reproduce this client's portion scaling, which is the only
 * reason a predicted "+31 g protein" is still true after Apply.
 */
export function draftToSmartPlateInput(
  draft: MealDraft,
  mealId?: number | null,
): SmartPlateInput {
  const items: SmartPlateItemInput[] = draft.items.map((item) => ({
    name: item.name.trim(),
    portion_amount: toNumber(item.portion_amount),
    portion_unit: item.portion_unit.trim() || "serving",
    calories: toNumber(item.calories),
    protein: toNumber(item.protein),
    carbs: toNumber(item.carbs),
    fat: toNumber(item.fat),
    base_portion_amount: item.base_portion_amount,
    base_calories: item.base_calories,
    base_protein: item.base_protein,
    base_carbs: item.base_carbs,
    base_fat: item.base_fat,
    confidence: item.confidence,
    locked_macros: item.locked_macros,
  }));

  return mealId ? { meal_id: mealId, items } : { items };
}

/**
 * A fingerprint of everything an analysis depends on.
 *
 * Re-analysing is cheap but not free, and React will hand us a new draft object
 * for every keystroke anywhere on the screen — including the meal name and meal
 * type, which Smart Plate does not look at. Comparing this string means a
 * request goes out when the *plate* changed, and not otherwise.
 */
export function smartPlateSignature(
  draft: MealDraft,
  mealId?: number | null,
): string {
  return JSON.stringify([mealId ?? null, draftToSmartPlateInput(draft).items]);
}

/* ---------------------------------------------------------------------------
   Applying a suggestion
   --------------------------------------------------------------------------- */

export interface ApplyResult {
  draft: MealDraft;
  ok: boolean;
  /** Set when the suggestion could not be applied safely. */
  reason?: string;
}

/**
 * Apply an optimization to the draft.
 *
 * Portion changes go through `setItemPortion`, the same function the portion
 * input uses, so the existing rules hold without being restated here: macros
 * rescale from the AI baseline, hand-edited macros stay locked, and an item
 * with no baseline is left alone.
 *
 * A suggestion is refused rather than half-applied when the item it names is no
 * longer where it was — the user edited the plate between the analysis and the
 * tap. The caller re-analyses instead.
 */
export function applySmartPlateChanges(
  draft: MealDraft,
  changes: SmartPlateChange[],
): ApplyResult {
  let items = draft.items;

  for (const change of changes) {
    if (change.action === "add_item") {
      items = [
        ...items,
        draftItemFromSuggestion({
          name: change.item_name,
          portion_amount: change.portion_amount,
          portion_unit: change.portion_unit,
          calories: change.macros.calories,
          protein: change.macros.protein,
          carbs: change.macros.carbs,
          fat: change.macros.fat,
        }),
      ];

      continue;
    }

    const target = items[change.item_index];

    if (!target || target.name.trim() !== change.item_name.trim()) {
      return {
        draft,
        ok: false,
        reason:
          "This suggestion was built from an earlier version of the meal. Recalculating…",
      };
    }

    items = items.map((item, index) =>
      index === change.item_index
        ? setItemPortion(item, String(change.to_portion))
        : item,
    );
  }

  return { draft: { ...draft, items }, ok: true };
}

/* ---------------------------------------------------------------------------
   Presentation
   --------------------------------------------------------------------------- */

/**
 * Status meta for the breakdown rows.
 *
 * Every status pairs an icon with a written label, so meaning never rests on
 * colour alone. `tone` maps onto the existing macro palette rather than
 * introducing new colours.
 */
export const SMART_PLATE_STATUS_META: Record<
  SmartPlateMacroStatus,
  { icon: LucideIcon; className: string; needsAttention: boolean }
> = {
  excellent: { icon: CircleCheck, className: "text-primary", needsAttention: false },
  good: { icon: CircleCheck, className: "text-primary", needsAttention: false },
  on_track: {
    icon: CircleCheck,
    className: "text-muted-foreground",
    needsAttention: false,
  },
  high: { icon: TrendingUp, className: "text-carbs", needsAttention: true },
  low: { icon: TrendingDown, className: "text-carbs", needsAttention: true },
  needs_attention: { icon: CircleAlert, className: "text-fat", needsAttention: true },
};

/** How the headline score reads. */
export const SMART_PLATE_RATING_META: Record<
  SmartPlateRating,
  { className: string; trackClassName: string }
> = {
  excellent_fit: { className: "text-primary", trackClassName: "bg-primary" },
  great_fit: { className: "text-primary", trackClassName: "bg-primary" },
  good_fit: { className: "text-foreground", trackClassName: "bg-primary/70" },
  fair_fit: { className: "text-carbs", trackClassName: "bg-carbs" },
  poor_fit: { className: "text-fat", trackClassName: "bg-fat" },
};

/** The macro rows, in the order the breakdown is read. */
export const SMART_PLATE_ROWS: MacroField[] = [
  "protein",
  "carbs",
  "fat",
  "calories",
];

/** "+31" / "−12" / "0" — an explicit sign, because the direction is the point. */
export function signedMacro(value: number, unit = "g"): string {
  const rounded = Math.round(value * 10) / 10;

  if (rounded === 0) return `0${unit}`;

  const display = Number.isInteger(rounded)
    ? Math.abs(rounded).toString()
    : Math.abs(rounded).toFixed(1);

  // A real minus sign rather than a hyphen: it lines up in tabular figures.
  return `${rounded > 0 ? "+" : "−"}${display}${unit}`;
}

export function signedCalories(value: number): string {
  const rounded = Math.round(value);

  if (rounded === 0) return "0";

  return `${rounded > 0 ? "+" : "−"}${Math.abs(rounded).toLocaleString("en-US")}`;
}
