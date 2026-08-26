"use client";

import * as React from "react";
import Link from "next/link";
import { AlertCircle, BarChart3, Camera, RefreshCw, Table2 } from "lucide-react";

import { cn } from "@/lib/utils";
import { ApiError } from "@/lib/api-client";
import { formatShortDate } from "@/lib/dates";
import { MACRO_META, formatCalories, formatMacro } from "@/lib/nutrition";
import { analyticsService } from "@/services";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { StatTile } from "@/components/shared/stat-tile";
import { TrendChart } from "@/components/charts/trend-chart";
import { RANGE_OPTIONS, RangeTabs } from "@/components/analytics/range-tabs";
import { AnalyticsTable } from "@/components/analytics/analytics-table";
import { TargetAdherenceCard } from "@/components/analytics/target-adherence-card";
import type { MacroKey } from "@/types";
import type { AnalyticsRange, AnalyticsReport } from "@/types/api";

const CHARTS: { macro: MacroKey; title: string; targetKey: MacroKey }[] = [
  { macro: "calories", title: "Calories over time", targetKey: "calories" },
  { macro: "protein", title: "Protein over time", targetKey: "protein" },
  { macro: "carbs", title: "Carbohydrates over time", targetKey: "carbs" },
  { macro: "fat", title: "Fat over time", targetKey: "fat" },
];

export function AnalyticsScreen() {
  const [range, setRange] = React.useState<AnalyticsRange>("week");
  const [report, setReport] = React.useState<AnalyticsReport | null>(null);
  const [loading, setLoading] = React.useState(true);
  const [error, setError] = React.useState<string | null>(null);
  const [showTable, setShowTable] = React.useState(false);
  const [reloadKey, setReloadKey] = React.useState(0);

  // Every setState sits after an await, so the effect body itself never
  // triggers a synchronous re-render. Switching range or retrying raises the
  // loading flag in the handler that caused it, where it belongs.
  React.useEffect(() => {
    let cancelled = false;

    void (async () => {
      try {
        const { data } = await analyticsService.report(range);
        if (cancelled) return;
        setReport(data);
        setError(null);
      } catch (caught) {
        if (cancelled) return;
        setError(
          caught instanceof ApiError
            ? caught.message
            : "Could not load your analytics.",
        );
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [range, reloadKey]);

  const changeRange = (next: AnalyticsRange) => {
    if (next === range) return;
    setLoading(true);
    setRange(next);
  };

  const retry = () => {
    setLoading(true);
    setError(null);
    setReloadKey((key) => key + 1);
  };

  const rangeLabel =
    RANGE_OPTIONS.find((option) => option.value === range)?.description ??
    "this period";

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Analytics"
        title="Your trends"
        description="Everything here is built from the meals you have actually logged. Days with nothing logged are shown as gaps, not zeroes."
      />

      <RangeTabs value={range} onChange={changeRange} disabled={loading} />

      {loading && <AnalyticsSkeleton />}

      {!loading && error && (
        <div
          role="alert"
          className="flex flex-col gap-4 rounded-2xl bg-card p-6 ring-1 ring-destructive/25 sm:flex-row sm:items-center sm:justify-between"
        >
          <div className="flex items-start gap-3">
            <AlertCircle className="mt-0.5 size-5 shrink-0 text-destructive" />
            <div>
              <p className="text-sm font-semibold">Could not load your analytics</p>
              <p className="mt-1 text-sm text-muted-foreground">{error}</p>
            </div>
          </div>
          <Button variant="outline" onClick={retry}>
            <RefreshCw />
            Try again
          </Button>
        </div>
      )}

      {!loading && !error && report && (
        <>
          {report.summary.days_logged === 0 ? (
            <NoDataYet />
          ) : (
            <>
              <SummaryStats report={report} rangeLabel={rangeLabel} />

              <TargetAdherenceCard
                adherence={report.summary.target_adherence}
                rangeLabel={rangeLabel}
              />

              <div className="space-y-5">
                {CHARTS.map((chart) => (
                  <ChartCard
                    key={chart.macro}
                    title={chart.title}
                    macro={chart.macro}
                    report={report}
                  />
                ))}
              </div>

              <section className="rounded-2xl bg-card p-5 ring-1 ring-foreground/10 sm:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div>
                    <h2 className="font-heading text-[0.9375rem] font-semibold">
                      The numbers behind the charts
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                      Every day in this range, newest first.
                    </p>
                  </div>
                  <Button
                    variant="outline"
                    onClick={() => setShowTable((open) => !open)}
                    aria-expanded={showTable}
                  >
                    <Table2 />
                    {showTable ? "Hide table" : "Show table"}
                  </Button>
                </div>

                {showTable && (
                  <div className="mt-5">
                    <AnalyticsTable report={report} />
                  </div>
                )}
              </section>
            </>
          )}
        </>
      )}
    </div>
  );
}

function ChartCard({
  title,
  macro,
  report,
}: {
  title: string;
  macro: MacroKey;
  report: AnalyticsReport;
}) {
  const meta = MACRO_META[macro];
  const target = report.targets?.[macro] ?? null;
  const average = report.summary.averages[macro];

  return (
    <section className="rounded-2xl bg-card p-4 ring-1 ring-foreground/10 sm:p-6">
      <header className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 className="flex items-center gap-2 font-heading text-[0.9375rem] font-semibold">
          <span
            aria-hidden="true"
            className="size-2.5 rounded-full"
            style={{ backgroundColor: meta.cssVar }}
          />
          {title}
        </h2>
        <p className="text-[0.75rem] text-muted-foreground tabular-nums">
          Avg{" "}
          <span className="font-semibold text-foreground">
            {macro === "calories"
              ? `${formatCalories(average)} kcal`
              : formatMacro(average, meta.unit)}
          </span>
          {typeof target === "number" && target > 0 && (
            <>
              {" · target "}
              {macro === "calories"
                ? `${formatCalories(target)} kcal`
                : formatMacro(target, meta.unit)}
            </>
          )}
        </p>
      </header>

      <div className="mt-4">
        <TrendChart
          points={report.series}
          macro={macro}
          target={target}
          granularity={report.range.granularity}
        />
      </div>

      <p className="mt-3 text-[0.6875rem] text-muted-foreground">
        {report.range.granularity === "week"
          ? "Each point is one week, averaged over the days you logged in it."
          : `${formatShortDate(report.range.from)} – ${formatShortDate(report.range.to)}`}
      </p>
    </section>
  );
}

function SummaryStats({
  report,
  rangeLabel,
}: {
  report: AnalyticsReport;
  rangeLabel: string;
}) {
  const { averages, days_logged: daysLogged, total_meals: totalMeals } = report.summary;

  return (
    <section className="rounded-2xl bg-card p-5 ring-1 ring-foreground/10 sm:p-6">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 className="font-heading text-[0.9375rem] font-semibold">
          {rangeLabel} at a glance
        </h2>
        <p className="text-[0.75rem] text-muted-foreground">
          Averages are per day you logged — {daysLogged} of{" "}
          {report.summary.days_in_range}.
        </p>
      </div>

      <div className="mt-4 grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-6">
        <StatTile
          label="Avg calories"
          value={formatCalories(averages.calories)}
          unit="kcal"
          dotColor={MACRO_META.calories.cssVar}
        />
        <StatTile
          label="Avg protein"
          value={formatMacro(averages.protein, "")}
          unit="g"
          dotColor={MACRO_META.protein.cssVar}
        />
        <StatTile
          label="Avg carbs"
          value={formatMacro(averages.carbs, "")}
          unit="g"
          dotColor={MACRO_META.carbs.cssVar}
        />
        <StatTile
          label="Avg fat"
          value={formatMacro(averages.fat, "")}
          unit="g"
          dotColor={MACRO_META.fat.cssVar}
        />
        <StatTile label="Meals logged" value={totalMeals} />
        <StatTile
          label="Days close"
          value={report.summary.target_adherence.days_close_to_target}
          hint={`of ${daysLogged} logged`}
        />
      </div>
    </section>
  );
}

function NoDataYet() {
  return (
    <section className="relative overflow-hidden rounded-2xl bg-card p-6 text-center ring-1 ring-foreground/10 sm:p-10">
      <div
        aria-hidden="true"
        className="brand-glow pointer-events-none absolute inset-0 opacity-60"
      />

      <div className="relative mx-auto max-w-md">
        <span className="mx-auto flex size-14 items-center justify-center rounded-2xl bg-primary/12 text-primary">
          <BarChart3 className="size-6" />
        </span>

        <h2 className="mt-5 font-heading text-xl font-semibold">
          Nothing to chart yet
        </h2>
        <p className="mt-2.5 text-sm leading-relaxed text-muted-foreground">
          Analytics are built entirely from your own logged meals, so there is
          nothing here until you log one. A few days is enough to see a trend.
        </p>

        <Button
          render={<Link href="/add-meal" />}
          size="lg"
          className="mt-7 w-full sm:w-auto"
        >
          <Camera />
          Log a meal
        </Button>
      </div>
    </section>
  );
}

function AnalyticsSkeleton() {
  return (
    <div className="space-y-5">
      <Skeleton className="h-32 rounded-2xl" />
      <Skeleton className="h-40 rounded-2xl" />
      {[0, 1].map((index) => (
        <div
          key={index}
          className={cn("rounded-2xl bg-card p-5 ring-1 ring-foreground/10")}
        >
          <Skeleton className="h-4 w-40" />
          <Skeleton className="mt-4 h-52 rounded-xl" />
        </div>
      ))}
    </div>
  );
}
