"use client";

import Link from "next/link";
import { Camera, Pencil, UtensilsCrossed } from "lucide-react";

import {
  MACRO_META,
  formatCalories,
  formatMacro,
  formatMealTime,
  mealTypeMeta,
} from "@/lib/nutrition";
import { Button } from "@/components/ui/button";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetTitle,
} from "@/components/ui/sheet";
import { AiDisclaimer } from "@/components/meals/ai-disclaimer";
import { MealTipCard } from "@/components/meals/nutrilens-tip";
import type { Meal } from "@/types/api";

/**
 * Read-only detail view. A bottom sheet on every breakpoint: it is the right
 * shape on mobile, and the content is short enough that it works on desktop too.
 */
export function MealDetailDialog({
  meal,
  onClose,
}: {
  meal: Meal | null;
  onClose: () => void;
}) {
  return (
    <Sheet open={meal !== null} onOpenChange={(open) => !open && onClose()}>
      <SheetContent
        side="bottom"
        className="max-h-[88dvh] gap-0 overflow-y-auto rounded-t-2xl p-0 sm:mx-auto sm:max-w-lg"
      >
        {meal && <MealDetail meal={meal} onClose={onClose} />}
      </SheetContent>
    </Sheet>
  );
}

function MealDetail({ meal, onClose }: { meal: Meal; onClose: () => void }) {
  const meta = mealTypeMeta(meal.meal_type);
  const items = meal.items ?? [];

  return (
    <>
      {meal.image_url ? (
        <div className="relative aspect-[16/9] w-full shrink-0 bg-muted">
          {/* Signed, expiring URL from the API — not a next/image source. */}
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            src={meal.image_url}
            alt={meal.meal_name}
            className="absolute inset-0 size-full object-cover"
          />
        </div>
      ) : (
        <div className="flex aspect-[16/6] w-full shrink-0 items-center justify-center bg-muted text-muted-foreground">
          <UtensilsCrossed className="size-7" />
        </div>
      )}

      <div className="space-y-5 p-5 pb-safe">
        <header>
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <SheetTitle className="truncate text-lg">{meal.meal_name}</SheetTitle>
              <SheetDescription className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                <span className="flex items-center gap-1.5">
                  <meta.icon className="size-3.5" />
                  {meta.label}
                </span>
                {formatMealTime(meal.consumed_at) && (
                  <>
                    <span aria-hidden="true">·</span>
                    <span className="tabular-nums">
                      {formatMealTime(meal.consumed_at)}
                    </span>
                  </>
                )}
                {meal.source === "ai_photo" && (
                  <>
                    <span aria-hidden="true">·</span>
                    <span className="flex items-center gap-1">
                      <Camera className="size-3" />
                      AI analysed
                    </span>
                  </>
                )}
              </SheetDescription>
            </div>
          </div>
        </header>

        {/* Totals */}
        <div className="rounded-xl bg-muted/60 p-3.5">
          <div className="flex items-baseline justify-between">
            <span className="text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase">
              Meal total
            </span>
            <span className="font-heading text-lg font-semibold tabular-nums">
              {formatCalories(meal.totals.calories)}
              <span className="ml-1 text-xs font-medium text-muted-foreground">
                kcal
              </span>
            </span>
          </div>

          <div className="mt-2.5 grid grid-cols-3 gap-2">
            {(["protein", "carbs", "fat"] as const).map((macro) => (
              <div key={macro} className="flex items-center gap-1.5">
                <span
                  aria-hidden="true"
                  className="size-2 shrink-0 rounded-full"
                  style={{ backgroundColor: MACRO_META[macro].cssVar }}
                />
                <span className="text-[0.6875rem] text-muted-foreground">
                  {MACRO_META[macro].short}
                </span>
                <span className="ml-auto text-[0.8125rem] font-semibold tabular-nums">
                  {formatMacro(meal.totals[macro])}
                </span>
              </div>
            ))}
          </div>
        </div>

        {/* How this meal sits against the rest of the day. Computed, not
            generated — no AI call is made to show it. */}
        <MealTipCard mealId={meal.id} />

        {/* Items */}
        {items.length > 0 && (
          <section>
            <h3 className="text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase">
              {items.length} item{items.length === 1 ? "" : "s"}
            </h3>

            <ul className="mt-2.5 divide-y divide-border overflow-hidden rounded-xl ring-1 ring-border">
              {items.map((item) => (
                <li key={item.id} className="flex items-start gap-3 px-3.5 py-3">
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-[0.8125rem] font-medium">
                      {item.name}
                    </p>
                    <p className="mt-0.5 text-[0.6875rem] text-muted-foreground tabular-nums">
                      {item.portion_amount} {item.portion_unit}
                      {" · P"}
                      {formatMacro(item.protein, "")} C
                      {formatMacro(item.carbs, "")} F{formatMacro(item.fat, "")}
                    </p>
                  </div>

                  <span className="shrink-0 text-[0.8125rem] font-semibold tabular-nums">
                    {formatCalories(item.calories)}
                  </span>
                </li>
              ))}
            </ul>
          </section>
        )}

        {meal.notes && (
          <p className="rounded-lg bg-muted/60 px-3.5 py-3 text-[0.8125rem] leading-relaxed text-muted-foreground">
            {meal.notes}
          </p>
        )}

        {meal.source === "ai_photo" && <AiDisclaimer />}

        <div className="flex gap-2.5">
          <Button variant="outline" size="lg" onClick={onClose} className="flex-1">
            Close
          </Button>
          <Button
            size="lg"
            render={<Link href={`/meals/${meal.id}/edit`} />}
            className="flex-1"
          >
            <Pencil />
            Edit
          </Button>
        </div>
      </div>
    </>
  );
}
