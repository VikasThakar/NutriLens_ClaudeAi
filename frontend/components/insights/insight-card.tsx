import { Lightbulb, Sparkles, TrendingDown, TrendingUp } from "lucide-react";

import { cn } from "@/lib/utils";
import { formatWeekRange } from "@/lib/dates";
import { MACRO_META, formatCalories, formatMacro } from "@/lib/nutrition";
import { StatTile } from "@/components/shared/stat-tile";
import type { WeeklyInsight } from "@/types/api";

/**
 * One weekly summary: the AI narrative, and the figures it was written from.
 *
 * The figures are shown alongside deliberately. The narrative is generated; the
 * numbers are not, and a reader should be able to check one against the other.
 */
export function InsightCard({
  insight,
  stale = false,
  className,
}: {
  insight: WeeklyInsight;
  /** The meals behind this summary have changed since it was written. */
  stale?: boolean;
  className?: string;
}) {
  const { stats, comparison } = insight;

  return (
    <article
      className={cn(
        "rounded-2xl bg-card p-5 ring-1 ring-foreground/10 sm:p-6",
        className,
      )}
    >
      <header className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
        <div className="min-w-0">
          <p className="text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase">
            {insight.week_start && insight.week_end
              ? formatWeekRange(insight.week_start, insight.week_end)
              : "This week"}
          </p>
          <h2 className="mt-1 flex items-start gap-2 font-heading text-lg font-semibold">
            <Sparkles className="mt-1 size-4 shrink-0 text-primary" />
            <span className="min-w-0">{insight.headline ?? "Your week"}</span>
          </h2>
        </div>

        {stale && (
          <span className="rounded-full bg-carbs/15 px-2.5 py-1 text-[0.6875rem] font-semibold text-carbs">
            Meals changed since this was written
          </span>
        )}
      </header>

      {insight.summary && (
        <p className="mt-4 text-sm leading-relaxed text-foreground/90">
          {insight.summary}
        </p>
      )}

      {/* The figures the narrative describes. */}
      <div className="mt-5 grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-5">
        <StatTile
          label="Avg calories"
          value={formatCalories(stats.avg_calories)}
          unit="kcal"
          hint={changeHint(stats.avg_calories, comparison?.averages.calories)}
          dotColor={MACRO_META.calories.cssVar}
        />
        <StatTile
          label="Avg protein"
          value={formatMacro(stats.avg_protein, "")}
          unit="g"
          hint={changeHint(stats.avg_protein, comparison?.averages.protein, "g")}
          dotColor={MACRO_META.protein.cssVar}
        />
        <StatTile
          label="Avg carbs"
          value={formatMacro(stats.avg_carbs, "")}
          unit="g"
          hint={changeHint(stats.avg_carbs, comparison?.averages.carbs, "g")}
          dotColor={MACRO_META.carbs.cssVar}
        />
        <StatTile
          label="Avg fat"
          value={formatMacro(stats.avg_fat, "")}
          unit="g"
          hint={changeHint(stats.avg_fat, comparison?.averages.fat, "g")}
          dotColor={MACRO_META.fat.cssVar}
        />
        <StatTile
          label="Days logged"
          value={`${stats.days_logged} of 7`}
          hint={`${stats.meals_logged} meal${stats.meals_logged === 1 ? "" : "s"}`}
        />
      </div>

      {stats.calorie_target !== null && (
        <p className="mt-3 flex items-center gap-1.5 text-[0.75rem] text-muted-foreground">
          {stats.days_close_to_target >= stats.days_logged / 2 ? (
            <TrendingUp className="size-3.5 text-primary" />
          ) : (
            <TrendingDown className="size-3.5" />
          )}
          <span>
            <span className="font-semibold text-foreground tabular-nums">
              {stats.days_close_to_target} of {stats.days_logged}
            </span>{" "}
            logged days landed close to your{" "}
            {formatCalories(stats.calorie_target)} kcal target.
          </span>
        </p>
      )}

      {insight.observations.length > 0 && (
        <section className="mt-5">
          <h3 className="text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase">
            What stood out
          </h3>
          <ul className="mt-2.5 space-y-2">
            {insight.observations.map((observation) => (
              <li key={observation} className="flex items-start gap-2.5 text-sm">
                <span
                  aria-hidden="true"
                  className="mt-[0.4375rem] size-1.5 shrink-0 rounded-full bg-primary"
                />
                <span className="text-muted-foreground">{observation}</span>
              </li>
            ))}
          </ul>
        </section>
      )}

      {insight.suggestions.length > 0 && (
        <section className="mt-5 rounded-xl bg-muted/60 p-4">
          <h3 className="flex items-center gap-1.5 text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase">
            <Lightbulb className="size-3.5" />
            Worth trying
          </h3>
          <ul className="mt-2.5 space-y-2">
            {insight.suggestions.map((suggestion) => (
              <li key={suggestion} className="flex items-start gap-2.5 text-sm">
                <span
                  aria-hidden="true"
                  className="mt-[0.4375rem] size-1.5 shrink-0 rounded-full bg-muted-foreground/60"
                />
                <span className="text-muted-foreground">{suggestion}</span>
              </li>
            ))}
          </ul>
        </section>
      )}

      <footer className="mt-5 border-t border-border pt-3.5">
        <p className="text-[0.6875rem] leading-relaxed text-muted-foreground">
          Written by AI from your weekly totals only — no meal names or photos
          are sent. It is a description of your own logged data, not nutrition or
          medical advice.
          {insight.generated_at && (
            <>
              {" "}
              Generated{" "}
              {new Date(insight.generated_at).toLocaleString("en-US", {
                dateStyle: "medium",
                timeStyle: "short",
              })}
              .
            </>
          )}
        </p>
      </footer>
    </article>
  );
}

/** "+15g vs last week", or nothing when there is no comparison. */
function changeHint(
  current: number,
  previous: number | undefined,
  unit = "",
): string | undefined {
  if (previous === undefined) return undefined;

  const delta = Math.round((current - previous) * 10) / 10;

  if (delta === 0) return "same as last week";

  const value = unit === "" ? formatCalories(Math.abs(delta)) : `${Math.abs(delta)}${unit}`;

  return `${delta > 0 ? "+" : "−"}${value} vs last week`;
}
