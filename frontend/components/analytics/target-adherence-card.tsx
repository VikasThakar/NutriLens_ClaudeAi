import Link from "next/link";
import { Target } from "lucide-react";

import { formatCalories } from "@/lib/nutrition";
import { Button } from "@/components/ui/button";
import type { TargetAdherence } from "@/types/api";

/**
 * "Days close to your target."
 *
 * Deliberately not called a score, and deliberately not a single opaque
 * percentage: the card states the rule it applied, the tolerance it used, the
 * target it measured against, and the denominator. A user should be able to
 * recompute this number by hand from their own history.
 */
export function TargetAdherenceCard({
  adherence,
  rangeLabel,
}: {
  adherence: TargetAdherence;
  rangeLabel: string;
}) {
  const {
    days_close_to_target: close,
    days_logged: logged,
    tolerance_percent: tolerance,
    calorie_target: target,
  } = adherence;

  if (!target) {
    return (
      <section className="flex flex-col gap-4 rounded-2xl bg-card p-5 ring-1 ring-foreground/10 sm:flex-row sm:items-center sm:justify-between sm:p-6">
        <div className="flex items-start gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/12 text-primary">
            <Target className="size-[1.125rem]" />
          </span>
          <div>
            <h2 className="font-heading text-[0.9375rem] font-semibold">
              Days close to your target
            </h2>
            <p className="mt-1 text-sm text-muted-foreground">
              Set a daily calorie target and NutriLens can tell you how many days
              landed near it.
            </p>
          </div>
        </div>
        <Button render={<Link href="/goals" />} variant="outline">
          Set targets
        </Button>
      </section>
    );
  }

  const lower = Math.round(target * (1 - tolerance / 100));
  const upper = Math.round(target * (1 + tolerance / 100));

  return (
    <section className="rounded-2xl bg-card p-5 ring-1 ring-foreground/10 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-4">
        <div className="min-w-0">
          <h2 className="font-heading text-[0.9375rem] font-semibold">
            Days close to your target
          </h2>
          <p className="mt-1 text-sm leading-relaxed text-muted-foreground">
            A day counts when its total calories land within {tolerance}% of your{" "}
            {formatCalories(target)} kcal target — that is{" "}
            <span className="font-medium text-foreground tabular-nums">
              {formatCalories(lower)}–{formatCalories(upper)} kcal
            </span>
            . Days with nothing logged are not counted either way.
          </p>
        </div>

        <p className="font-heading text-2xl font-semibold tabular-nums">
          {close}
          <span className="text-base font-medium text-muted-foreground">
            {" "}
            of {logged}
          </span>
        </p>
      </div>

      {logged > 0 ? (
        <>
          <div
            className="mt-4 h-2 w-full overflow-hidden rounded-full bg-muted"
            role="progressbar"
            aria-label="Days close to target"
            aria-valuenow={close}
            aria-valuemin={0}
            aria-valuemax={logged}
          >
            <div
              className="h-full rounded-full bg-primary transition-[width] duration-700 ease-out"
              style={{ width: `${(close / logged) * 100}%` }}
            />
          </div>
          <p className="mt-2.5 text-[0.75rem] text-muted-foreground">
            Out of the {logged} day{logged === 1 ? "" : "s"} you logged in{" "}
            {rangeLabel.toLowerCase()}.
          </p>
        </>
      ) : (
        <p className="mt-4 text-[0.8125rem] text-muted-foreground">
          Nothing logged in this period yet.
        </p>
      )}
    </section>
  );
}
