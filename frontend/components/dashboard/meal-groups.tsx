"use client";

import * as React from "react";

import { formatCalories, mealTypeMeta } from "@/lib/nutrition";
import { MealDetailDialog } from "@/components/meals/meal-detail-dialog";
import { MealRow } from "@/components/meals/meal-row";
import type { Meal, MealGroup } from "@/types/api";

interface MealGroupsProps {
  groups: MealGroup[];
  onDelete: (meal: Meal) => void;
  deletingId: number | null;
}

export function MealGroups({ groups, onDelete, deletingId }: MealGroupsProps) {
  const [viewing, setViewing] = React.useState<Meal | null>(null);

  // Only render buckets that have something in them — four empty headings is
  // clutter, not structure.
  const populated = groups.filter((group) => group.meal_count > 0);

  return (
    <>
      <div className="space-y-5">
        {populated.map((group) => {
          const meta = mealTypeMeta(group.meal_type);

          return (
            <section key={group.meal_type}>
              <header className="mb-2.5 flex items-center gap-2.5 px-1">
                <span className="flex size-7 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                  <meta.icon className="size-3.5" />
                </span>
                <h2 className="font-heading text-[0.9375rem] font-semibold">
                  {group.label}
                </h2>
                <span className="ml-auto text-[0.8125rem] font-medium text-muted-foreground tabular-nums">
                  {formatCalories(group.totals.calories)} kcal
                </span>
              </header>

              <ul className="space-y-2.5">
                {group.meals.map((meal) => (
                  <MealRow
                    key={meal.id}
                    meal={meal}
                    onView={() => setViewing(meal)}
                    onDelete={() => onDelete(meal)}
                    deleting={deletingId === meal.id}
                  />
                ))}
              </ul>
            </section>
          );
        })}
      </div>

      <MealDetailDialog meal={viewing} onClose={() => setViewing(null)} />
    </>
  );
}
