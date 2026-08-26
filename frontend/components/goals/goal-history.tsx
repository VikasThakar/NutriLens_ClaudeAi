"use client";

import * as React from "react";
import { History } from "lucide-react";

import { formatShortDate } from "@/lib/dates";
import { formatCalories } from "@/lib/nutrition";
import { goalsService } from "@/services";
import { Button } from "@/components/ui/button";
import type { NutritionGoal } from "@/types/api";

/**
 * Previous targets.
 *
 * Changing a goal retires the old one rather than overwriting it, so this is a
 * real record of what the user was tracking against and when — worth showing,
 * because Analytics compares past days against *current* targets and this is
 * where you can see that the target itself moved.
 */
export function GoalHistory({ reloadKey }: { reloadKey: number }) {
  const [goals, setGoals] = React.useState<NutritionGoal[] | null>(null);
  const [open, setOpen] = React.useState(false);
  const [failed, setFailed] = React.useState(false);

  React.useEffect(() => {
    let cancelled = false;

    void (async () => {
      try {
        const { data } = await goalsService.history();
        if (!cancelled) setGoals(data);
      } catch {
        // A missing history panel is not worth an error state on this page.
        if (!cancelled) setFailed(true);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [reloadKey]);

  const previous = (goals ?? []).filter((goal) => !goal.is_active);

  if (failed || previous.length === 0) return null;

  return (
    <section className="rounded-2xl bg-card p-5 ring-1 ring-foreground/10 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-start gap-3">
          <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
            <History className="size-4" />
          </span>
          <div>
            <h2 className="font-heading text-[0.9375rem] font-semibold">
              Previous targets
            </h2>
            <p className="mt-0.5 text-sm text-muted-foreground">
              {previous.length} earlier target{previous.length === 1 ? "" : "s"},
              kept so your history stays honest.
            </p>
          </div>
        </div>
        <Button
          variant="ghost"
          size="sm"
          aria-expanded={open}
          onClick={() => setOpen((current) => !current)}
        >
          {open ? "Hide" : "Show"}
        </Button>
      </div>

      {open && (
        <ul className="mt-4 divide-y divide-border overflow-hidden rounded-xl ring-1 ring-border">
          {previous.map((goal) => (
            <li
              key={goal.id}
              className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-3.5 py-3"
            >
              <div className="min-w-0">
                <p className="text-[0.8125rem] font-medium">{goal.goal_label}</p>
                <p className="text-[0.6875rem] text-muted-foreground">
                  {goal.effective_from
                    ? `From ${formatShortDate(goal.effective_from)}`
                    : "Date unknown"}
                  {goal.source_label ? ` · ${goal.source_label}` : ""}
                </p>
              </div>
              <p className="text-[0.75rem] text-muted-foreground tabular-nums">
                {formatCalories(goal.calorie_target)} kcal ·{" "}
                {goal.protein_target}P / {goal.carb_target}C / {goal.fat_target}F
              </p>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
