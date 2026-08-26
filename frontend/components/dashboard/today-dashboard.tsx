"use client";

import * as React from "react";
import Link from "next/link";
import { AlertCircle, Camera, RefreshCw, Target } from "lucide-react";
import { toast } from "sonner";

import { ApiError } from "@/lib/api-client";
import {
  MACRO_META,
  firstName,
  formatCalories,
  greetingFor,
  progressPercent,
} from "@/lib/nutrition";
import { mealsService } from "@/services";
import { useAuth } from "@/hooks/use-auth";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { CalorieRing } from "@/components/dashboard/calorie-ring";
import { MacroBar } from "@/components/dashboard/macro-bar";
import { EmptyMeals } from "@/components/dashboard/empty-meals";
import { FirstRun } from "@/components/dashboard/first-run";
import { InsightPreview } from "@/components/dashboard/insight-preview";
import { MealGroups } from "@/components/dashboard/meal-groups";
import { QuickAdd } from "@/components/dashboard/quick-add";
import { StreakCard } from "@/components/dashboard/streak-card";
import { TrendPreview } from "@/components/dashboard/trend-preview";
import { MealDetailDialog } from "@/components/meals/meal-detail-dialog";
import { MealRow } from "@/components/meals/meal-row";
import type { Meal, TodaySummary } from "@/types/api";

function formatFullDate(date: Date): string {
  return date.toLocaleDateString("en-US", {
    weekday: "long",
    month: "long",
    day: "numeric",
  });
}

export function TodayDashboard() {
  const { user } = useAuth();
  const [summary, setSummary] = React.useState<TodaySummary | null>(null);
  const [error, setError] = React.useState<string | null>(null);
  const [loading, setLoading] = React.useState(true);
  /**
   * Resolved on the client only, alongside the fetch — the greeting and date
   * depend on the visitor's local clock, which the prerendered HTML cannot know.
   */
  const [now, setNow] = React.useState<Date | null>(null);
  const [reloadKey, setReloadKey] = React.useState(0);
  const [deletingId, setDeletingId] = React.useState<number | null>(null);
  const [viewing, setViewing] = React.useState<Meal | null>(null);

  React.useEffect(() => {
    let cancelled = false;

    // Every setState below sits after an await, so the effect body itself never
    // triggers a synchronous re-render.
    void (async () => {
      try {
        const { data } = await mealsService.today();
        if (cancelled) return;
        setSummary(data);
        setNow(new Date());
      } catch (caught) {
        if (cancelled) return;
        setError(
          caught instanceof ApiError
            ? caught.message
            : "Could not load today's summary.",
        );
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [reloadKey]);

  const refresh = () => setReloadKey((key) => key + 1);

  const retry = () => {
    setLoading(true);
    setError(null);
    refresh();
  };

  const deleteMeal = async (meal: Meal) => {
    setDeletingId(meal.id);

    try {
      await mealsService.remove(meal.id);
      toast.success(`${meal.meal_name} deleted.`);
      refresh();
    } catch (caught) {
      toast.error(
        caught instanceof ApiError ? caught.message : "Could not delete that meal.",
      );
    } finally {
      setDeletingId(null);
    }
  };

  const greeting = now ? greetingFor(now) : "Hello";
  const dateLabel = now ? formatFullDate(now) : "";

  // A brand-new account gets the first-run experience instead of a dashboard
  // full of zeroes. `has_any_meals` is what separates it from a quiet today.
  const isFirstRun = summary !== null && !summary.has_any_meals;

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow={dateLabel || undefined}
        title={
          <>
            {greeting}
            {user ? `, ${firstName(user.name)}` : ""}.
          </>
        }
        description={
          isFirstRun
            ? "Welcome to NutriLens. Here is how to get started."
            : "Here is how your day is tracking against your targets."
        }
        action={
          <Button render={<Link href="/add-meal" />} size="lg">
            <Camera />
            Add Meal
          </Button>
        }
      />

      {loading && <SummarySkeleton />}

      {!loading && error && (
        <div
          role="alert"
          className="flex flex-col gap-4 rounded-2xl bg-card p-6 ring-1 ring-destructive/25 sm:flex-row sm:items-center sm:justify-between"
        >
          <div className="flex items-start gap-3">
            <AlertCircle className="mt-0.5 size-5 shrink-0 text-destructive" />
            <div>
              <p className="text-sm font-semibold">Could not load your day</p>
              <p className="mt-1 text-sm text-muted-foreground">{error}</p>
            </div>
          </div>
          <Button variant="outline" onClick={retry}>
            <RefreshCw />
            Try again
          </Button>
        </div>
      )}

      {!loading && !error && summary && (
        <>
          {isFirstRun ? (
            <FirstRun hasGoal={summary.goal !== null} />
          ) : (
            <>
              {summary.goal ? <MacroSummary summary={summary} /> : <NoGoalPrompt />}

              {/* Streak and trend side by side on desktop, stacked on mobile. */}
              <div className="grid gap-5 lg:grid-cols-2">
                <StreakCard streak={summary.streak} />
                <TrendPreview
                  trend={summary.trend}
                  calorieTarget={summary.goal?.calorie_target ?? null}
                />
              </div>

              {summary.meal_count === 0 ? (
                <>
                  <EmptyMeals />
                  {summary.recent_meals.length > 0 && (
                    <RecentMeals
                      meals={summary.recent_meals}
                      onView={setViewing}
                      onDelete={(meal) => void deleteMeal(meal)}
                      deletingId={deletingId}
                    />
                  )}
                </>
              ) : (
                <MealGroups
                  groups={summary.groups}
                  onDelete={(meal) => void deleteMeal(meal)}
                  deletingId={deletingId}
                />
              )}

              <InsightPreview insight={summary.latest_insight} />

              <QuickAdd />
            </>
          )}
        </>
      )}

      <MealDetailDialog meal={viewing} onClose={() => setViewing(null)} />
    </div>
  );
}

function MacroSummary({ summary }: { summary: TodaySummary }) {
  const goal = summary.goal!;
  const { consumed } = summary;
  const caloriePercent = Math.round(
    progressPercent(consumed.calories, goal.calorie_target),
  );

  return (
    <section className="rounded-2xl bg-card p-5 ring-1 ring-foreground/10 elevate sm:p-7">
      <div className="flex flex-col items-center gap-7 sm:flex-row sm:items-center sm:gap-9">
        <div className="flex flex-col items-center gap-3">
          <CalorieRing consumed={consumed.calories} target={goal.calorie_target} />
          <p className="text-[0.6875rem] font-medium tracking-wide text-muted-foreground uppercase">
            {goal.goal_label} · {caloriePercent}% of target
          </p>
        </div>

        <div className="w-full flex-1 space-y-5">
          <div className="flex items-baseline justify-between gap-3 border-b border-border pb-4">
            <div>
              <p className="text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase">
                Calories
              </p>
              <p className="mt-1 font-heading text-xl font-semibold tabular-nums">
                {formatCalories(consumed.calories)}
                <span className="text-sm font-medium text-muted-foreground">
                  {" "}
                  / {formatCalories(goal.calorie_target)} {MACRO_META.calories.unit}
                </span>
              </p>
            </div>
            <span
              className="text-xs font-medium tabular-nums"
              style={{ color: MACRO_META.calories.cssVar }}
            >
              {formatCalories(Math.max(0, goal.calorie_target - consumed.calories))}{" "}
              left
            </span>
          </div>

          <div className="grid gap-5 sm:grid-cols-3">
            <MacroBar
              macro="protein"
              consumed={consumed.protein}
              target={goal.protein_target}
            />
            <MacroBar
              macro="carbs"
              consumed={consumed.carbs}
              target={goal.carb_target}
            />
            <MacroBar macro="fat" consumed={consumed.fat} target={goal.fat_target} />
          </div>
        </div>
      </div>
    </section>
  );
}

/**
 * Shown when today is empty but the account is not: the most recent meals give
 * the dashboard something real to display, and a way back into editing them.
 */
function RecentMeals({
  meals,
  onView,
  onDelete,
  deletingId,
}: {
  meals: Meal[];
  onView: (meal: Meal) => void;
  onDelete: (meal: Meal) => void;
  deletingId: number | null;
}) {
  return (
    <section>
      <h2 className="mb-2.5 px-1 font-heading text-[0.9375rem] font-semibold">
        Recently logged
      </h2>
      <ul className="space-y-2.5">
        {meals.map((meal) => (
          <MealRow
            key={meal.id}
            meal={meal}
            showMealType
            onView={() => onView(meal)}
            onDelete={() => onDelete(meal)}
            deleting={deletingId === meal.id}
          />
        ))}
      </ul>
    </section>
  );
}

function NoGoalPrompt() {
  return (
    <section className="flex flex-col gap-4 rounded-2xl bg-card p-6 ring-1 ring-foreground/10 sm:flex-row sm:items-center sm:justify-between">
      <div className="flex items-start gap-3">
        <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/12 text-primary">
          <Target className="size-[1.125rem]" />
        </span>
        <div>
          <p className="text-sm font-semibold">No daily targets set</p>
          <p className="mt-1 text-sm text-muted-foreground">
            Set your calories and macros so NutriLens can track progress.
          </p>
        </div>
      </div>
      <Button render={<Link href="/goals" />} variant="outline">
        Set targets
      </Button>
    </section>
  );
}

function SummarySkeleton() {
  return (
    <div className="space-y-6">
      <div className="rounded-2xl bg-card p-5 ring-1 ring-foreground/10 sm:p-7">
        <div className="flex flex-col items-center gap-7 sm:flex-row sm:gap-9">
          <Skeleton className="size-44 shrink-0 rounded-full" />
          <div className="w-full flex-1 space-y-5">
            <Skeleton className="h-14 w-full" />
            <div className="grid gap-5 sm:grid-cols-3">
              <Skeleton className="h-12" />
              <Skeleton className="h-12" />
              <Skeleton className="h-12" />
            </div>
          </div>
        </div>
      </div>
      <div className="grid gap-5 lg:grid-cols-2">
        <Skeleton className="h-56 rounded-2xl" />
        <Skeleton className="h-56 rounded-2xl" />
      </div>
      <Skeleton className="h-64 rounded-2xl" />
    </div>
  );
}
