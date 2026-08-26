import Link from "next/link";
import { ArrowRight, BarChart3 } from "lucide-react";

import { cn } from "@/lib/utils";
import { formatCalories } from "@/lib/nutrition";
import { Button } from "@/components/ui/button";
import { WeekBars } from "@/components/charts/week-bars";
import type { DailyNutritionPoint } from "@/types/api";

/**
 * A seven-day calorie preview on the dashboard, with the full charts one tap
 * away. The average shown is over the days that were actually logged, so it
 * matches the figure Analytics reports.
 */
export function TrendPreview({
  trend,
  calorieTarget,
  className,
}: {
  trend: DailyNutritionPoint[];
  calorieTarget: number | null;
  className?: string;
}) {
  const logged = trend.filter((day) => day.logged);

  const average =
    logged.length > 0
      ? Math.round(logged.reduce((sum, day) => sum + day.calories, 0) / logged.length)
      : 0;

  return (
    <section
      className={cn(
        "flex flex-col rounded-2xl bg-card p-5 ring-1 ring-foreground/10 sm:p-6",
        className,
      )}
    >
      <div className="flex items-start justify-between gap-4">
        <div className="min-w-0">
          <p className="text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase">
            Last 7 days
          </p>
          {logged.length > 0 ? (
            <p className="mt-1.5 flex items-baseline gap-2">
              <span className="font-heading text-3xl font-semibold tabular-nums">
                {formatCalories(average)}
              </span>
              <span className="text-sm font-medium text-muted-foreground">
                kcal / day
              </span>
            </p>
          ) : (
            <p className="mt-1.5 font-heading text-xl font-semibold text-muted-foreground">
              Nothing logged yet
            </p>
          )}
          <p className="mt-1 text-[0.8125rem] text-muted-foreground">
            {logged.length > 0
              ? `Averaged over the ${logged.length} day${logged.length === 1 ? "" : "s"} you logged.`
              : "Log a meal and your trend starts here."}
          </p>
        </div>

        <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-muted text-muted-foreground">
          <BarChart3 className="size-5" />
        </span>
      </div>

      <div className="mt-5">
        <WeekBars points={trend} target={calorieTarget} />
      </div>

      <Button
        render={<Link href="/analytics" />}
        variant="ghost"
        size="sm"
        className="mt-4 self-start"
      >
        See all analytics
        <ArrowRight />
      </Button>
    </section>
  );
}
