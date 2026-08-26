import { MACRO_META, formatCalories, formatMacro, progressPercent } from "@/lib/nutrition";
import { StatTile } from "@/components/shared/stat-tile";
import { CalorieRing } from "@/components/dashboard/calorie-ring";
import type { HistoryDay } from "@/types/api";

/**
 * The totals for one past day, measured against the targets in force now.
 *
 * The wording is careful about that: goals are kept as history in the database,
 * but this compares against the *current* target, which is not the same thing
 * as the target the user had on that day.
 */
export function DaySummaryCard({ day }: { day: HistoryDay }) {
  const { totals, goal } = day;

  return (
    <section className="rounded-2xl bg-card p-5 ring-1 ring-foreground/10 sm:p-6">
      <div className="flex flex-col items-center gap-6 sm:flex-row sm:items-center sm:gap-8">
        {goal ? (
          <div className="flex shrink-0 flex-col items-center gap-2">
            <CalorieRing
              consumed={totals.calories}
              target={goal.calorie_target}
              size={132}
              strokeWidth={10}
            />
            <p className="text-[0.6875rem] font-medium tracking-wide text-muted-foreground uppercase">
              {Math.round(progressPercent(totals.calories, goal.calorie_target))}% of
              target
            </p>
          </div>
        ) : (
          <div className="flex shrink-0 flex-col items-center">
            <p className="font-heading text-3xl font-semibold tabular-nums">
              {formatCalories(totals.calories)}
            </p>
            <p className="text-[0.6875rem] font-medium tracking-wide text-muted-foreground uppercase">
              kcal logged
            </p>
          </div>
        )}

        <div className="grid w-full flex-1 grid-cols-2 gap-2.5 sm:grid-cols-3">
          <StatTile
            label="Calories"
            value={formatCalories(totals.calories)}
            unit="kcal"
            hint={goal ? `of ${formatCalories(goal.calorie_target)}` : undefined}
            dotColor={MACRO_META.calories.cssVar}
          />
          <StatTile
            label="Protein"
            value={formatMacro(totals.protein, "")}
            unit="g"
            hint={goal ? `of ${goal.protein_target}g` : undefined}
            dotColor={MACRO_META.protein.cssVar}
          />
          <StatTile
            label="Carbs"
            value={formatMacro(totals.carbs, "")}
            unit="g"
            hint={goal ? `of ${goal.carb_target}g` : undefined}
            dotColor={MACRO_META.carbs.cssVar}
          />
          <StatTile
            label="Fat"
            value={formatMacro(totals.fat, "")}
            unit="g"
            hint={goal ? `of ${goal.fat_target}g` : undefined}
            dotColor={MACRO_META.fat.cssVar}
          />
          <StatTile
            label="Meals"
            value={day.meal_count}
            className="col-span-2 sm:col-span-1"
          />
        </div>
      </div>

      {goal && (
        <p className="mt-4 text-[0.6875rem] text-muted-foreground">
          Compared against your current targets, not the targets you had on this
          day.
        </p>
      )}
    </section>
  );
}
