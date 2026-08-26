import type {
  AnalyzedMeal,
  MacroField,
  MacroTotals,
  Meal,
  MealItemInput,
  MealType,
  StoreMealInput,
} from "@/types/api";

/**
 * The editable client-side model of a meal, plus the portion-scaling rules.
 *
 * Numbers are held as strings because they are bound to text inputs, where a
 * half-typed value ("", "1.") has to survive a render. They are parsed once, at
 * the edges: when computing totals and when submitting.
 */

export const MACRO_FIELDS: MacroField[] = ["calories", "protein", "carbs", "fat"];

export const PORTION_UNITS = [
  "g",
  "ml",
  "oz",
  "fl oz",
  "cup",
  "tbsp",
  "tsp",
  "slice",
  "piece",
  "serving",
  "bowl",
  "plate",
] as const;

export interface DraftItem {
  /** Stable client-side key; unrelated to any database id. */
  key: string;
  name: string;
  portion_amount: string;
  portion_unit: string;
  calories: string;
  protein: string;
  carbs: string;
  fat: string;

  /** The AI's original estimate — the base every portion change scales from. */
  base_portion_amount: number | null;
  base_calories: number | null;
  base_protein: number | null;
  base_carbs: number | null;
  base_fat: number | null;

  confidence: number | null;
  is_ai_generated: boolean;
  was_edited: boolean;
  /** Macros the user has typed themselves; portion scaling leaves these alone. */
  locked_macros: MacroField[];
}

export interface MealDraft {
  meal_name: string;
  meal_type: MealType;
  items: DraftItem[];
  notes: string | null;
  /** Null for a manual meal. */
  ai_confidence: number | null;
  ai_provider: string | null;
  ai_model: string | null;
  meal_image_id: number | null;
  image_url: string | null;
  consumed_at: string | null;
}

let keyCounter = 0;

function nextKey(): string {
  keyCounter += 1;
  return `item-${keyCounter}`;
}

/* ---------------------------------------------------------------------------
   Formatting
   --------------------------------------------------------------------------- */

/** Calories are whole numbers; macro grams carry one decimal. */
export function formatMacroValue(field: MacroField, value: number): string {
  if (!Number.isFinite(value) || value < 0) return "0";

  if (field === "calories") {
    return String(Math.round(value));
  }

  const rounded = Math.round(value * 10) / 10;
  return Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(1);
}

function formatPortion(value: number): string {
  if (!Number.isFinite(value)) return "";
  const rounded = Math.round(value * 100) / 100;
  return Number.isInteger(rounded) ? String(rounded) : String(rounded);
}

/** Parses an input string, treating anything unusable as 0. */
export function toNumber(value: string): number {
  const parsed = Number.parseFloat(value);
  return Number.isFinite(parsed) && parsed >= 0 ? parsed : 0;
}

/* ---------------------------------------------------------------------------
   Building drafts
   --------------------------------------------------------------------------- */

export function emptyDraftItem(): DraftItem {
  return {
    key: nextKey(),
    name: "",
    portion_amount: "100",
    portion_unit: "g",
    calories: "",
    protein: "",
    carbs: "",
    fat: "",
    base_portion_amount: null,
    base_calories: null,
    base_protein: null,
    base_carbs: null,
    base_fat: null,
    confidence: null,
    is_ai_generated: false,
    was_edited: false,
    locked_macros: [],
  };
}

export function draftFromAnalysis(
  analysis: AnalyzedMeal,
  mealType: MealType,
  image: { id: number; url: string } | null,
): MealDraft {
  return {
    meal_name: analysis.meal_name,
    meal_type: mealType,
    notes: analysis.notes,
    ai_confidence: analysis.confidence,
    ai_provider: analysis.provider,
    ai_model: analysis.model,
    meal_image_id: image?.id ?? null,
    image_url: image?.url ?? null,
    consumed_at: null,
    items: analysis.items.map((item) => ({
      key: nextKey(),
      name: item.name,
      portion_amount: formatPortion(item.portion_amount),
      portion_unit: item.portion_unit,
      calories: formatMacroValue("calories", item.calories),
      protein: formatMacroValue("protein", item.protein),
      carbs: formatMacroValue("carbs", item.carbs),
      fat: formatMacroValue("fat", item.fat),
      // The estimate as returned is the baseline for all later scaling.
      base_portion_amount: item.portion_amount,
      base_calories: item.calories,
      base_protein: item.protein,
      base_carbs: item.carbs,
      base_fat: item.fat,
      confidence: item.confidence,
      is_ai_generated: true,
      was_edited: false,
      locked_macros: [],
    })),
  };
}

/**
 * A food item Smart Plate suggested adding.
 *
 * Its own values double as its baseline, so the moment it lands on the plate it
 * behaves exactly like any other item: change the portion and the macros
 * rescale. `is_ai_generated` stays false and it carries no confidence — the
 * numbers come from NutriLens's own reference table, not from the vision model,
 * and dressing them up as an AI estimate would misrepresent both.
 */
export function draftItemFromSuggestion(input: {
  name: string;
  portion_amount: number;
  portion_unit: string;
  calories: number;
  protein: number;
  carbs: number;
  fat: number;
}): DraftItem {
  return {
    key: nextKey(),
    name: input.name,
    portion_amount: formatPortion(input.portion_amount),
    portion_unit: input.portion_unit,
    calories: formatMacroValue("calories", input.calories),
    protein: formatMacroValue("protein", input.protein),
    carbs: formatMacroValue("carbs", input.carbs),
    fat: formatMacroValue("fat", input.fat),
    base_portion_amount: input.portion_amount,
    base_calories: input.calories,
    base_protein: input.protein,
    base_carbs: input.carbs,
    base_fat: input.fat,
    confidence: null,
    is_ai_generated: false,
    was_edited: false,
    locked_macros: [],
  };
}

/** An empty manual meal. */
export function draftForManualEntry(
  mealType: MealType,
  image: { id: number; url: string } | null = null,
): MealDraft {
  return {
    meal_name: "",
    meal_type: mealType,
    notes: null,
    ai_confidence: null,
    ai_provider: null,
    ai_model: null,
    meal_image_id: image?.id ?? null,
    image_url: image?.url ?? null,
    consumed_at: null,
    items: [emptyDraftItem()],
  };
}

/** Rehydrate a saved meal for editing, baseline and locks intact. */
export function draftFromMeal(meal: Meal): MealDraft {
  return {
    meal_name: meal.meal_name,
    meal_type: meal.meal_type,
    notes: meal.notes,
    ai_confidence: meal.ai_confidence,
    ai_provider: meal.ai_provider,
    ai_model: meal.ai_model,
    meal_image_id: null,
    image_url: meal.image_url,
    consumed_at: meal.consumed_at,
    items: (meal.items ?? []).map((item) => ({
      key: nextKey(),
      name: item.name,
      portion_amount: formatPortion(item.portion_amount),
      portion_unit: item.portion_unit,
      calories: formatMacroValue("calories", item.calories),
      protein: formatMacroValue("protein", item.protein),
      carbs: formatMacroValue("carbs", item.carbs),
      fat: formatMacroValue("fat", item.fat),
      base_portion_amount: item.base_portion_amount,
      base_calories: item.base_calories,
      base_protein: item.base_protein,
      base_carbs: item.base_carbs,
      base_fat: item.base_fat,
      confidence: item.confidence,
      is_ai_generated: item.is_ai_generated,
      was_edited: item.was_edited,
      locked_macros: item.locked_macros ?? [],
    })),
  };
}

/* ---------------------------------------------------------------------------
   Editing
   --------------------------------------------------------------------------- */

function baseValueFor(item: DraftItem, field: MacroField): number | null {
  switch (field) {
    case "calories":
      return item.base_calories;
    case "protein":
      return item.base_protein;
    case "carbs":
      return item.base_carbs;
    case "fat":
      return item.base_fat;
  }
}

export function hasBaseline(item: DraftItem): boolean {
  return item.base_portion_amount !== null && item.base_portion_amount > 0;
}

/**
 * Change an item's portion and rescale its macros proportionally from the AI
 * baseline — 150 g at 250 kcal becomes 500 kcal at 300 g.
 *
 * Two rules keep this from surprising the user:
 *   1. Any macro they have typed themselves is locked and never overwritten.
 *   2. With no baseline (a manual item), nothing is scaled at all — we have no
 *      reference point, so guessing would be worse than doing nothing.
 */
export function setItemPortion(item: DraftItem, portion: string): DraftItem {
  const next: DraftItem = { ...item, portion_amount: portion, was_edited: true };

  if (!hasBaseline(item)) return next;

  const amount = Number.parseFloat(portion);
  if (!Number.isFinite(amount) || amount <= 0) return next;

  const ratio = amount / (item.base_portion_amount as number);

  for (const field of MACRO_FIELDS) {
    if (item.locked_macros.includes(field)) continue;

    const base = baseValueFor(item, field);
    if (base === null) continue;

    next[field] = formatMacroValue(field, base * ratio);
  }

  return next;
}

/**
 * A hand-typed macro. The field is locked so later portion changes respect it.
 */
export function setItemMacro(
  item: DraftItem,
  field: MacroField,
  value: string,
): DraftItem {
  return {
    ...item,
    [field]: value,
    was_edited: true,
    locked_macros: item.locked_macros.includes(field)
      ? item.locked_macros
      : [...item.locked_macros, field],
  };
}

export function setItemName(item: DraftItem, name: string): DraftItem {
  return { ...item, name, was_edited: true };
}

export function setItemUnit(item: DraftItem, unit: string): DraftItem {
  // Changing "g" to "cup" makes the numeric baseline meaningless, so scaling is
  // retired rather than left to produce nonsense on the next portion edit.
  return {
    ...item,
    portion_unit: unit,
    was_edited: true,
    base_portion_amount: null,
  };
}

/** Undo every manual edit on an item and return to the AI's estimate. */
export function resetItemToEstimate(item: DraftItem): DraftItem {
  if (!hasBaseline(item)) return item;

  return {
    ...item,
    portion_amount: formatPortion(item.base_portion_amount as number),
    calories: formatMacroValue("calories", item.base_calories ?? 0),
    protein: formatMacroValue("protein", item.base_protein ?? 0),
    carbs: formatMacroValue("carbs", item.base_carbs ?? 0),
    fat: formatMacroValue("fat", item.base_fat ?? 0),
    locked_macros: [],
    was_edited: false,
  };
}

export function itemIsDirty(item: DraftItem): boolean {
  return item.was_edited || item.locked_macros.length > 0;
}

/* ---------------------------------------------------------------------------
   Totals & submission
   --------------------------------------------------------------------------- */

export function draftTotals(items: DraftItem[]): MacroTotals {
  const totals = items.reduce<MacroTotals>(
    (sum, item) => ({
      calories: sum.calories + toNumber(item.calories),
      protein: sum.protein + toNumber(item.protein),
      carbs: sum.carbs + toNumber(item.carbs),
      fat: sum.fat + toNumber(item.fat),
    }),
    { calories: 0, protein: 0, carbs: 0, fat: 0 },
  );

  return {
    calories: Math.round(totals.calories),
    protein: Math.round(totals.protein * 10) / 10,
    carbs: Math.round(totals.carbs * 10) / 10,
    fat: Math.round(totals.fat * 10) / 10,
  };
}

/** Weakest item confidence — what the review screen should draw attention to. */
export function lowestConfidence(items: DraftItem[]): number | null {
  const values = items
    .map((item) => item.confidence)
    .filter((value): value is number => value !== null);

  return values.length ? Math.min(...values) : null;
}

export interface DraftValidation {
  ok: boolean;
  /** Per-item-key field errors, plus a `meal_name` key for the meal itself. */
  errors: Record<string, string>;
}

export function validateDraft(draft: MealDraft): DraftValidation {
  const errors: Record<string, string> = {};

  if (draft.meal_name.trim().length < 2) {
    errors.meal_name = "Give this meal a name.";
  }

  if (draft.items.length === 0) {
    errors.items = "Add at least one food item.";
  }

  for (const item of draft.items) {
    if (item.name.trim() === "") {
      errors[`${item.key}.name`] = "Name required.";
    }
    if (toNumber(item.portion_amount) <= 0) {
      errors[`${item.key}.portion_amount`] = "Must be more than 0.";
    }
  }

  return { ok: Object.keys(errors).length === 0, errors };
}

export function draftToStoreInput(draft: MealDraft): StoreMealInput {
  return {
    meal_name: draft.meal_name.trim(),
    meal_type: draft.meal_type,
    source: draft.ai_provider ? "ai_photo" : "manual",
    notes: draft.notes,
    ai_confidence: draft.ai_confidence,
    ai_provider: draft.ai_provider,
    ai_model: draft.ai_model,
    meal_image_id: draft.meal_image_id,
    items: draft.items.map(toItemInput),
  };
}

export function draftToUpdateInput(draft: MealDraft) {
  return {
    meal_name: draft.meal_name.trim(),
    meal_type: draft.meal_type,
    notes: draft.notes,
    items: draft.items.map(toItemInput),
  };
}

function toItemInput(item: DraftItem): MealItemInput {
  return {
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
    is_ai_generated: item.is_ai_generated,
    was_edited: item.was_edited,
    locked_macros: item.locked_macros,
  };
}
