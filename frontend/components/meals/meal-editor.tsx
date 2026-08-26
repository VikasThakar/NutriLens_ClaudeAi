"use client";

import * as React from "react";
import { Loader2, Plus, Sparkles } from "lucide-react";

import { cn } from "@/lib/utils";
import { MACRO_META, MEAL_TYPES, formatCalories, formatMacro } from "@/lib/nutrition";
import {
  draftTotals,
  emptyDraftItem,
  resetItemToEstimate,
  setItemMacro,
  setItemName,
  setItemPortion,
  setItemUnit,
  type MealDraft,
} from "@/lib/meal-draft";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { FormError } from "@/components/shared/form-message";
import { FoodItemCard } from "@/components/meals/food-item-card";
import { AiDisclaimer } from "@/components/meals/ai-disclaimer";
import type { MacroField, MacroTotals, MealType } from "@/types/api";

interface MealEditorProps {
  draft: MealDraft;
  onChange: (draft: MealDraft) => void;
  onSubmit: () => void;
  onCancel: () => void;
  /** Field errors keyed as `meal_name` / `<itemKey>.<field>`. */
  errors: Record<string, string>;
  formError?: string | null;
  saving?: boolean;
  submitLabel: string;
  title: string;
  description?: string;
  /**
   * Rendered between the food items and the totals bar — the point in the flow
   * where the meal has been reviewed and is about to be saved.
   *
   * A slot rather than a prop bundle: the editor stays an editor, and does not
   * need to know what Smart Plate is or which meal it is analysing.
   */
  smartPlate?: React.ReactNode;
}

export function MealEditor({
  draft,
  onChange,
  onSubmit,
  onCancel,
  errors,
  formError,
  saving = false,
  submitLabel,
  title,
  description,
  smartPlate,
}: MealEditorProps) {
  const totals = React.useMemo(() => draftTotals(draft.items), [draft.items]);
  const isAi = draft.ai_provider !== null;

  const updateItem = (key: string, next: ReturnType<typeof emptyDraftItem>) => {
    onChange({
      ...draft,
      items: draft.items.map((item) => (item.key === key ? next : item)),
    });
  };

  const addItem = () => {
    onChange({ ...draft, items: [...draft.items, emptyDraftItem()] });
  };

  const removeItem = (key: string) => {
    onChange({ ...draft, items: draft.items.filter((item) => item.key !== key) });
  };

  return (
    <div className="space-y-5">
      <FormError message={formError} />

      {/* Photo + meal identity */}
      <section className="overflow-hidden rounded-2xl bg-card ring-1 ring-foreground/10">
        {draft.image_url && (
          <div className="relative aspect-[16/9] w-full bg-muted sm:aspect-[21/9]">
            {/* Signed, expiring URL from the API — not a next/image source. */}
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src={draft.image_url}
              alt={draft.meal_name || "Your meal"}
              className="absolute inset-0 size-full object-cover"
            />
          </div>
        )}

        <div className="space-y-4 p-4 sm:p-5">
          <div>
            <h2 className="font-heading text-base font-semibold">{title}</h2>
            {description && (
              <p className="mt-1 text-sm leading-relaxed text-muted-foreground">
                {description}
              </p>
            )}
          </div>

          {isAi && (
            <p className="text-[0.75rem] text-muted-foreground">
              {draft.items.length} item{draft.items.length === 1 ? "" : "s"} detected
            </p>
          )}

          {isAi && draft.notes && (
            <p className="flex items-start gap-2 rounded-lg bg-muted/70 px-3.5 py-3 text-[0.8125rem] leading-relaxed text-muted-foreground">
              <Sparkles className="mt-px size-3.5 shrink-0 text-primary" />
              <span>{draft.notes}</span>
            </p>
          )}

          <div className="space-y-2">
            <Label htmlFor="meal-name">Meal name</Label>
            <Input
              id="meal-name"
              value={draft.meal_name}
              placeholder="e.g. Chicken Rice Bowl"
              aria-invalid={Boolean(errors.meal_name)}
              onChange={(event) =>
                onChange({ ...draft, meal_name: event.target.value })
              }
            />
            {errors.meal_name && (
              <p className="text-[0.75rem] text-destructive">{errors.meal_name}</p>
            )}
          </div>

          <fieldset>
            <legend className="text-sm leading-none font-medium">Meal type</legend>
            <div
              role="radiogroup"
              aria-label="Meal type"
              className="mt-2.5 grid grid-cols-2 gap-2 sm:grid-cols-4"
            >
              {MEAL_TYPES.map((option) => {
                const selected = draft.meal_type === option.value;

                return (
                  <button
                    key={option.value}
                    type="button"
                    role="radio"
                    aria-checked={selected}
                    onClick={() =>
                      onChange({ ...draft, meal_type: option.value as MealType })
                    }
                    className={cn(
                      "flex h-10 items-center justify-center gap-1.5 rounded-lg text-[0.8125rem] font-medium transition-all",
                      "focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
                      selected
                        ? "bg-primary text-primary-foreground"
                        : "bg-muted text-muted-foreground hover:text-foreground",
                    )}
                  >
                    <option.icon className="size-4" />
                    {option.label}
                  </button>
                );
              })}
            </div>
          </fieldset>

          {isAi && <AiDisclaimer />}
        </div>
      </section>

      {/* Items */}
      <section>
        <div className="mb-3 flex items-center justify-between gap-3">
          <h2 className="font-heading text-[0.9375rem] font-semibold">
            Food items
          </h2>
          <span className="text-[0.75rem] text-muted-foreground tabular-nums">
            {draft.items.length} total
          </span>
        </div>

        {errors.items && (
          <p className="mb-3 text-[0.8125rem] text-destructive">{errors.items}</p>
        )}

        <ul className="space-y-3">
          {draft.items.map((item, index) => (
            <FoodItemCard
              key={item.key}
              item={item}
              index={index}
              errors={errors}
              canRemove={draft.items.length > 1}
              onNameChange={(value) => updateItem(item.key, setItemName(item, value))}
              onPortionChange={(value) =>
                updateItem(item.key, setItemPortion(item, value))
              }
              onUnitChange={(value) => updateItem(item.key, setItemUnit(item, value))}
              onMacroChange={(field: MacroField, value) =>
                updateItem(item.key, setItemMacro(item, field, value))
              }
              onReset={() => updateItem(item.key, resetItemToEstimate(item))}
              onRemove={() => removeItem(item.key)}
            />
          ))}
        </ul>

        <Button
          variant="outline"
          size="lg"
          onClick={addItem}
          className="mt-3 w-full border-dashed"
        >
          <Plus />
          Add Food Item
        </Button>
      </section>

      {smartPlate}

      {/* Spacer so the sticky bar never covers the last field */}
      <div className="h-2" />

      <MealTotalsBar
        totals={totals}
        onSubmit={onSubmit}
        onCancel={onCancel}
        saving={saving}
        submitLabel={submitLabel}
      />
    </div>
  );
}

/**
 * Live totals plus the primary action, pinned above the mobile bottom
 * navigation so saving is always one thumb-reach away.
 */
function MealTotalsBar({
  totals,
  onSubmit,
  onCancel,
  saving,
  submitLabel,
}: {
  totals: MacroTotals;
  onSubmit: () => void;
  onCancel: () => void;
  saving: boolean;
  submitLabel: string;
}) {
  return (
    <div className="sticky bottom-20 z-20 lg:bottom-4">
      <div className="rounded-2xl border border-border bg-background/95 p-3 backdrop-blur-lg elevate-lg sm:p-4">
        <div className="flex items-baseline justify-between gap-3">
          <span className="text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase">
            Meal total
          </span>
          <span className="font-heading text-xl font-semibold tabular-nums">
            {formatCalories(totals.calories)}
            <span className="ml-1 text-xs font-medium text-muted-foreground">
              kcal
            </span>
          </span>
        </div>

        <div className="mt-2.5 grid grid-cols-3 gap-2">
          {(["protein", "carbs", "fat"] as const).map((macro) => (
            <div
              key={macro}
              className="flex items-center gap-1.5 rounded-lg bg-muted/70 px-2.5 py-1.5"
            >
              <span
                aria-hidden="true"
                className="size-2 shrink-0 rounded-full"
                style={{ backgroundColor: MACRO_META[macro].cssVar }}
              />
              <span className="text-[0.6875rem] text-muted-foreground">
                {MACRO_META[macro].short}
              </span>
              <span className="ml-auto text-[0.8125rem] font-semibold tabular-nums">
                {formatMacro(totals[macro])}
              </span>
            </div>
          ))}
        </div>

        <div className="mt-3 flex gap-2.5">
          <Button
            variant="ghost"
            size="lg"
            onClick={onCancel}
            disabled={saving}
            className="text-muted-foreground"
          >
            Cancel
          </Button>
          <Button size="lg" onClick={onSubmit} disabled={saving} className="flex-1">
            {saving ? (
              <>
                <Loader2 className="animate-spin" />
                Saving…
              </>
            ) : (
              submitLabel
            )}
          </Button>
        </div>
      </div>
    </div>
  );
}
