"use client";

import Link from "next/link";
import { Flame, Target } from "lucide-react";

import { cn } from "@/lib/utils";
import { formatDayLabel } from "@/lib/dates";
import {
  MACRO_META,
  formatCalories,
  formatMacro,
  progressPercent,
  rawPercent,
} from "@/lib/nutrition";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import type { CoachContext, MacroKey } from "@/types";

/**
 * The compact "Today's progress" strip at the top of the AI Coach screen.
 *
 * These are the same figures the coach itself was given — the endpoint returns
 * one object that feeds both — so the card can never show one number while an
 * answer quotes another. It is deliberately small: it exists to tell the user
 * what the coach knows, not to replace the dashboard.
 */
export function CoachProgress({
  context,
  className,
}: {
  context: CoachContext;
  className?: string;
}) {
  const { targets, consumed, remaining } = context;

  return (
    <section
      className={cn(
        "relative overflow-hidden rounded-2xl bg-card p-4 ring-1 ring-foreground/10 sm:p-5",
        className,
      )}
      aria-labelledby="coach-progress-heading"
    >
      <div
        aria-hidden="true"
        className="brand-glow pointer-events-none absolute inset-0 opacity-40"
      />

      <div className="relative">
        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
          <div className="min-w-0">
            <h2
              id="coach-progress-heading"
              className="text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase"
            >
              Today&apos;s progress
              <span className="ml-1.5 font-medium normal-case">
                · {formatDayLabel(context.date)}
              </span>
            </h2>
          </div>

          <div className="flex flex-wrap items-center gap-1.5">
            {context.goal && (
              <Badge variant="secondary" className="font-medium">
                {context.goal}
              </Badge>
            )}
            {context.streak.current_days > 0 && (
              <Badge variant="outline" className="gap-1 font-medium">
                <Flame className="text-primary" />
                {context.streak.current_days} day
                {context.streak.current_days === 1 ? "" : "s"}
              </Badge>
            )}
            {context.is_simulated && (
              <Badge
                variant="outline"
                className="font-medium text-muted-foreground"
                title="No AI key is configured on this server, so replies are composed offline from your own data."
              >
                Simulated
              </Badge>
            )}
          </div>
        </div>

        {targets ? (
          <>
            <div className="mt-3.5 grid grid-cols-2 gap-2 sm:grid-cols-4">
              <Meter macro="calories" consumed={consumed.calories} target={targets.calories} />
              <Meter macro="protein" consumed={consumed.protein} target={targets.protein} />
              <Meter macro="carbs" consumed={consumed.carbs} target={targets.carbs} />
              <Meter macro="fat" consumed={consumed.fat} target={targets.fat} />
            </div>

            {remaining && (
              <p className="mt-3 text-[0.8125rem] leading-relaxed text-muted-foreground">
                <RemainingLine remaining={remaining} />
                {" · "}
                {context.meals_logged_today === 0
                  ? "nothing logged yet today"
                  : `${context.meals_logged_today} meal${
                      context.meals_logged_today === 1 ? "" : "s"
                    } logged today`}
              </p>
            )}
          </>
        ) : (
          <NoTargets context={context} />
        )}
      </div>
    </section>
  );
}

function Meter({
  macro,
  consumed,
  target,
}: {
  macro: MacroKey;
  consumed: number;
  target: number;
}) {
  const meta = MACRO_META[macro];
  const percent = progressPercent(consumed, target);
  const over = rawPercent(consumed, target) > 100;
  const isCalories = macro === "calories";

  return (
    <div className="rounded-xl bg-muted/50 px-3 py-2.5 ring-1 ring-foreground/5">
      <p className="flex items-center gap-1.5 text-[0.625rem] font-semibold tracking-wide text-muted-foreground uppercase">
        <span
          aria-hidden="true"
          className="size-1.5 shrink-0 rounded-full"
          style={{ backgroundColor: meta.cssVar }}
        />
        <span className="truncate">{meta.short}</span>
      </p>

      <p className="mt-1 font-heading text-[0.9375rem] font-semibold tabular-nums sm:text-base">
        <span className={cn(over && "text-destructive")}>
          {isCalories ? formatCalories(consumed) : formatMacro(consumed, "")}
        </span>
        <span className="text-[0.6875rem] font-medium text-muted-foreground">
          {" / "}
          {isCalories ? formatCalories(target) : formatMacro(target, "")}
          {isCalories ? "" : "g"}
        </span>
      </p>

      <div
        className="mt-2 h-1 w-full overflow-hidden rounded-full bg-border/70"
        role="progressbar"
        aria-label={`${meta.label} progress`}
        aria-valuenow={Math.round(percent)}
        aria-valuemin={0}
        aria-valuemax={100}
      >
        <div
          className="h-full rounded-full transition-[width] duration-700 ease-out"
          style={{
            width: `${percent}%`,
            backgroundColor: over ? "var(--destructive)" : meta.cssVar,
          }}
        />
      </div>
    </div>
  );
}

/** "580 kcal and 68g protein left" — or the honest version when over. */
function RemainingLine({
  remaining,
}: {
  remaining: NonNullable<CoachContext["remaining"]>;
}) {
  if (remaining.calories < 0) {
    return (
      <span className="font-medium text-destructive">
        {formatCalories(Math.abs(remaining.calories))} kcal over target
      </span>
    );
  }

  return (
    <>
      <span className="font-medium text-foreground">
        {formatCalories(remaining.calories)} kcal
      </span>
      {remaining.protein > 0 && (
        <>
          {" and "}
          <span className="font-medium text-foreground">
            {formatMacro(remaining.protein)} protein
          </span>
        </>
      )}
      {" left"}
    </>
  );
}

function NoTargets({ context }: { context: CoachContext }) {
  return (
    <div className="mt-3.5 flex flex-col gap-3 rounded-xl bg-muted/50 p-3.5 ring-1 ring-foreground/5 sm:flex-row sm:items-center sm:justify-between">
      <div className="flex items-start gap-3">
        <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/12 text-primary">
          <Target className="size-4" />
        </span>
        <div className="min-w-0">
          <p className="text-[0.8125rem] font-semibold">No daily targets yet</p>
          <p className="mt-0.5 text-[0.8125rem] leading-relaxed text-muted-foreground">
            {context.consumed.calories > 0
              ? `You have logged ${formatCalories(context.consumed.calories)} kcal today. Set targets and your coach can tell you what is left.`
              : "Set your calories and macros so your coach can answer against real targets."}
          </p>
        </div>
      </div>
      <Button render={<Link href="/goals" />} variant="outline" size="sm">
        Set targets
      </Button>
    </div>
  );
}

export function CoachProgressSkeleton() {
  return (
    <div className="rounded-2xl bg-card p-4 ring-1 ring-foreground/10 sm:p-5">
      <Skeleton className="h-3.5 w-40" />
      <div className="mt-3.5 grid grid-cols-2 gap-2 sm:grid-cols-4">
        <Skeleton className="h-[4.75rem] rounded-xl" />
        <Skeleton className="h-[4.75rem] rounded-xl" />
        <Skeleton className="h-[4.75rem] rounded-xl" />
        <Skeleton className="h-[4.75rem] rounded-xl" />
      </div>
      <Skeleton className="mt-3 h-3.5 w-56" />
    </div>
  );
}
