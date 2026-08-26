"use client";

import * as React from "react";
import Link from "next/link";
import {
  AlertCircle,
  Camera,
  Loader2,
  RefreshCw,
  Sparkles,
} from "lucide-react";
import { toast } from "sonner";

import { ApiError } from "@/lib/api-client";
import { formatWeekRange } from "@/lib/dates";
import { formatCalories } from "@/lib/nutrition";
import { insightsService } from "@/services";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { StatTile } from "@/components/shared/stat-tile";
import { InsightCard } from "@/components/insights/insight-card";
import type { CurrentWeekInsight, WeeklyInsight } from "@/types/api";

export function InsightsScreen() {
  const [current, setCurrent] = React.useState<CurrentWeekInsight | null>(null);
  const [history, setHistory] = React.useState<WeeklyInsight[]>([]);
  const [loading, setLoading] = React.useState(true);
  const [loadError, setLoadError] = React.useState<string | null>(null);
  const [generating, setGenerating] = React.useState(false);
  const [generateError, setGenerateError] = React.useState<string | null>(null);
  const [reloadKey, setReloadKey] = React.useState(0);

  // Every setState sits after an await, so the effect body never triggers a
  // synchronous re-render.
  React.useEffect(() => {
    let cancelled = false;

    void (async () => {
      try {
        const [currentWeek, previous] = await Promise.all([
          insightsService.current(),
          insightsService.list(),
        ]);

        if (cancelled) return;

        setCurrent(currentWeek.data);
        setHistory(previous.data);
        setLoadError(null);
      } catch (caught) {
        if (cancelled) return;
        setLoadError(
          caught instanceof ApiError
            ? caught.message
            : "Could not load your insights.",
        );
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [reloadKey]);

  const reload = () => setReloadKey((key) => key + 1);

  const retryLoad = () => {
    setLoading(true);
    setLoadError(null);
    reload();
  };

  const generate = async (force = false) => {
    setGenerating(true);
    setGenerateError(null);

    try {
      const response = await insightsService.generate({ force });

      if (response.status === "insufficient_data") {
        // Not a failure — the week genuinely cannot support a summary yet.
        toast.info(response.message);
        reload();
        return;
      }

      toast.success(response.message);
      reload();
    } catch (caught) {
      const message =
        caught instanceof ApiError
          ? caught.message
          : "Could not generate your weekly summary.";
      setGenerateError(message);
    } finally {
      setGenerating(false);
    }
  };

  // The current week is rendered from `current`; the list below is everything
  // else, so the same week is never shown twice.
  const pastInsights = current
    ? history.filter((insight) => insight.week_start !== current.week_start)
    : history;

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Insights"
        title="Weekly summary"
        description="A short read on your week, written from your own logged totals. Nothing is invented and no meal names or photos leave your account."
      />

      {loading && <InsightsSkeleton />}

      {!loading && loadError && (
        <ErrorPanel
          title="Could not load your insights"
          message={loadError}
          onRetry={retryLoad}
        />
      )}

      {!loading && !loadError && current && (
        <>
          <CurrentWeekPanel
            current={current}
            generating={generating}
            onGenerate={generate}
          />

          {generateError && (
            <ErrorPanel
              title="Could not generate your summary"
              message={generateError}
              onRetry={() => void generate(true)}
              retryLabel="Try again"
            />
          )}

          {current.insight && (
            <InsightCard insight={current.insight} stale={current.is_stale} />
          )}

          {pastInsights.length > 0 && (
            <section className="space-y-4">
              <h2 className="px-1 font-heading text-[0.9375rem] font-semibold">
                Earlier weeks
              </h2>
              {pastInsights.map((insight) => (
                <InsightCard key={insight.id} insight={insight} />
              ))}
            </section>
          )}
        </>
      )}
    </div>
  );
}

function CurrentWeekPanel({
  current,
  generating,
  onGenerate,
}: {
  current: CurrentWeekInsight;
  generating: boolean;
  onGenerate: (force?: boolean) => void;
}) {
  const { aggregates, requirement } = current;
  const shortfall = requirement.min_days_logged - requirement.days_logged;

  return (
    <section className="relative overflow-hidden rounded-2xl bg-card p-5 ring-1 ring-foreground/10 sm:p-6">
      <div
        aria-hidden="true"
        className="brand-glow pointer-events-none absolute inset-0 opacity-50"
      />

      <div className="relative">
        <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-4">
          <div className="min-w-0">
            <p className="text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase">
              {current.is_current_week ? "This week" : "Selected week"} ·{" "}
              {formatWeekRange(current.week_start, current.week_end)}
            </p>
            <h2 className="mt-1 font-heading text-lg font-semibold">
              {current.insight
                ? current.is_stale
                  ? "Your summary is out of date"
                  : "Your summary is up to date"
                : current.has_enough_data
                  ? "Ready for a summary"
                  : "Not enough logged yet"}
            </h2>
            <p className="mt-1.5 max-w-lg text-sm leading-relaxed text-muted-foreground">
              {current.insight
                ? current.is_stale
                  ? "You have changed meals in this week since the summary was written. Regenerate it to describe the current numbers."
                  : "Nothing has changed since it was written, so there is no need to generate it again."
                : current.has_enough_data
                  ? `You have logged ${requirement.days_logged} day${requirement.days_logged === 1 ? "" : "s"} this week. That is enough to summarise.`
                  : `Log at least ${requirement.min_days_logged} days in a week before a summary can say anything true — ${shortfall} more to go.`}
            </p>
          </div>

          <Button
            size="lg"
            disabled={generating || !current.has_enough_data}
            onClick={() => onGenerate(current.is_stale)}
          >
            {generating ? (
              <>
                <Loader2 className="animate-spin" />
                Writing your summary…
              </>
            ) : (
              <>
                {current.insight ? <RefreshCw /> : <Sparkles />}
                {current.insight
                  ? current.is_stale
                    ? "Regenerate summary"
                    : "Summary up to date"
                  : "Generate Weekly Summary"}
              </>
            )}
          </Button>
        </div>

        {/* The real figures, whether or not a summary exists. */}
        <div className="mt-5 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
          <StatTile
            label="Days logged"
            value={`${aggregates.days_logged} of 7`}
          />
          <StatTile label="Meals" value={aggregates.meals_logged} />
          <StatTile
            label="Avg calories"
            value={
              aggregates.days_logged > 0
                ? formatCalories(aggregates.averages.calories)
                : "—"
            }
            unit={aggregates.days_logged > 0 ? "kcal" : undefined}
          />
          <StatTile
            label="Days close"
            value={
              aggregates.targets
                ? `${aggregates.days_close_to_target} of ${aggregates.days_logged}`
                : "—"
            }
            hint={
              aggregates.targets
                ? `within ${aggregates.tolerance_percent}% of target`
                : "no target set"
            }
          />
        </div>

        {aggregates.days_logged === 0 && (
          <Button
            render={<Link href="/add-meal" />}
            variant="outline"
            className="mt-5"
          >
            <Camera />
            Log your first meal this week
          </Button>
        )}
      </div>
    </section>
  );
}

function ErrorPanel({
  title,
  message,
  onRetry,
  retryLabel = "Try again",
}: {
  title: string;
  message: string;
  onRetry: () => void;
  retryLabel?: string;
}) {
  return (
    <div
      role="alert"
      className="flex flex-col gap-4 rounded-2xl bg-card p-6 ring-1 ring-destructive/25 sm:flex-row sm:items-center sm:justify-between"
    >
      <div className="flex items-start gap-3">
        <AlertCircle className="mt-0.5 size-5 shrink-0 text-destructive" />
        <div>
          <p className="text-sm font-semibold">{title}</p>
          <p className="mt-1 text-sm text-muted-foreground">{message}</p>
        </div>
      </div>
      <Button variant="outline" onClick={onRetry}>
        <RefreshCw />
        {retryLabel}
      </Button>
    </div>
  );
}

function InsightsSkeleton() {
  return (
    <div className="space-y-5">
      <Skeleton className="h-56 rounded-2xl" />
      <Skeleton className="h-72 rounded-2xl" />
    </div>
  );
}
