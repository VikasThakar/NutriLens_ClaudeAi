"use client";

import * as React from "react";
import Link from "next/link";
import { AlertCircle, Camera, RefreshCw, UtensilsCrossed } from "lucide-react";
import { toast } from "sonner";

import { ApiError } from "@/lib/api-client";
import { formatDayLabel, isToday, todayISO } from "@/lib/dates";
import { historyService, mealsService } from "@/services";
import { useHydrated } from "@/hooks/use-hydrated";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { MealDetailDialog } from "@/components/meals/meal-detail-dialog";
import { MealRow } from "@/components/meals/meal-row";
import { DayNavigator } from "@/components/history/day-navigator";
import { DaySummaryCard } from "@/components/history/day-summary-card";
import type { HistoryDay, Meal } from "@/types/api";

export function HistoryScreen() {
  const hydrated = useHydrated();

  const [chosenDate, setChosenDate] = React.useState<string | null>(null);
  const [day, setDay] = React.useState<HistoryDay | null>(null);
  const [loading, setLoading] = React.useState(true);
  const [error, setError] = React.useState<string | null>(null);
  const [reloadKey, setReloadKey] = React.useState(0);
  const [viewing, setViewing] = React.useState<Meal | null>(null);
  const [deletingId, setDeletingId] = React.useState<number | null>(null);

  /**
   * "Today" depends on the visitor's clock, which the prerendered HTML cannot
   * know — so it is derived during render once hydrated rather than written
   * from an effect.
   */
  const date = chosenDate ?? (hydrated ? todayISO() : null);

  // Every setState sits after an await, so the effect body never triggers a
  // synchronous re-render. The loading flag is raised by whatever changed the
  // date.
  React.useEffect(() => {
    if (!date) return;

    let cancelled = false;

    void (async () => {
      try {
        const { data } = await historyService.day(date);
        if (cancelled) return;
        setDay(data);
        setError(null);
      } catch (caught) {
        if (cancelled) return;
        setError(
          caught instanceof ApiError ? caught.message : "Could not load that day.",
        );
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [date, reloadKey]);

  const goToDate = (next: string) => {
    if (next === date) return;
    setLoading(true);
    setChosenDate(next);
  };

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

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="History"
        title="Meal history"
        description="Every meal you have logged, one day at a time."
        action={
          <Button render={<Link href="/add-meal" />} size="lg">
            <Camera />
            Add Meal
          </Button>
        }
      />

      {date && (
        <DayNavigator
          date={date}
          onChange={goToDate}
          previousLoggedDate={day?.previous_logged_date ?? null}
          nextLoggedDate={day?.next_logged_date ?? null}
          busy={loading}
        />
      )}

      {(loading || !date) && <DaySkeleton />}

      {!loading && error && (
        <div
          role="alert"
          className="flex flex-col gap-4 rounded-2xl bg-card p-6 ring-1 ring-destructive/25 sm:flex-row sm:items-center sm:justify-between"
        >
          <div className="flex items-start gap-3">
            <AlertCircle className="mt-0.5 size-5 shrink-0 text-destructive" />
            <div>
              <p className="text-sm font-semibold">Could not load that day</p>
              <p className="mt-1 text-sm text-muted-foreground">{error}</p>
            </div>
          </div>
          <Button variant="outline" onClick={retry}>
            <RefreshCw />
            Try again
          </Button>
        </div>
      )}

      {!loading && !error && day && (
        <>
          {day.meal_count > 0 ? (
            <>
              <DaySummaryCard day={day} />

              <section>
                <h2 className="mb-2.5 px-1 font-heading text-[0.9375rem] font-semibold">
                  {day.meal_count} meal{day.meal_count === 1 ? "" : "s"}
                </h2>
                <ul className="space-y-2.5">
                  {day.meals.map((meal) => (
                    <MealRow
                      key={meal.id}
                      meal={meal}
                      showMealType
                      onView={() => setViewing(meal)}
                      onDelete={() => void deleteMeal(meal)}
                      deleting={deletingId === meal.id}
                    />
                  ))}
                </ul>
              </section>
            </>
          ) : (
            <EmptyDay day={day} />
          )}
        </>
      )}

      <MealDetailDialog meal={viewing} onClose={() => setViewing(null)} />
    </div>
  );
}

function EmptyDay({ day }: { day: HistoryDay }) {
  const label = formatDayLabel(day.date);

  return (
    <section className="rounded-2xl bg-card p-8 text-center ring-1 ring-foreground/10">
      <span className="mx-auto flex size-12 items-center justify-center rounded-xl bg-muted text-muted-foreground">
        <UtensilsCrossed className="size-5" />
      </span>

      <h2 className="mt-4 font-heading text-lg font-semibold">
        {day.is_future
          ? "That day has not happened yet"
          : `No meals logged on ${label.toLowerCase() === "today" ? "today" : label}`}
      </h2>

      <p className="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-muted-foreground">
        {day.is_future
          ? "Pick a date in the past, or jump back to today."
          : day.previous_logged_date
            ? "Use the jump buttons above to skip straight to a day you did log."
            : "Nothing was recorded on this date."}
      </p>

      {isToday(day.date) && (
        <Button render={<Link href="/add-meal" />} className="mt-6">
          <Camera />
          Log a meal
        </Button>
      )}
    </section>
  );
}

function DaySkeleton() {
  return (
    <div className="space-y-5">
      <Skeleton className="h-44 rounded-2xl" />
      <div className="space-y-2.5">
        <Skeleton className="h-24 rounded-2xl" />
        <Skeleton className="h-24 rounded-2xl" />
      </div>
    </div>
  );
}
