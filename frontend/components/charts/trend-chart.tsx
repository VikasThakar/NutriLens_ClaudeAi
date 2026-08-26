"use client";

import * as React from "react";
import {
  CartesianGrid,
  Line,
  LineChart,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";

import { cn } from "@/lib/utils";
import {
  formatDayOfMonth,
  formatLongDate,
  formatShortDate,
  formatWeekRange,
  shiftDays,
} from "@/lib/dates";
import { MACRO_META, formatCalories, formatMacro } from "@/lib/nutrition";
import { ChartTooltipCard, ChartTooltipRow } from "@/components/charts/chart-tooltip";
import type { MacroKey } from "@/types";
import type { DailyNutritionPoint } from "@/types/api";

interface TrendChartProps {
  points: DailyNutritionPoint[];
  macro: MacroKey;
  /** The daily target, drawn as a reference line when one is set. */
  target?: number | null;
  /** `week` buckets label and describe themselves differently. */
  granularity?: "day" | "week";
  className?: string;
}

interface ChartPoint {
  date: string;
  /**
   * Null for a day with nothing logged. Deliberately not 0 — a gap in the line
   * is the truth, and a zero would read as "ate nothing".
   */
  value: number | null;
  meals: number;
  daysLogged?: number;
}

/**
 * One macro over time.
 *
 * Deliberately single-series: the four macros get four charts rather than four
 * lines on one axis. That keeps each chart to one scale (no dual axis, no
 * grams and kilocalories sharing a y), and means identity never rests on
 * telling two similar hues apart — the title names the series and the colour
 * only reinforces it.
 */
export function TrendChart({
  points,
  macro,
  target,
  granularity = "day",
  className,
}: TrendChartProps) {
  const meta = MACRO_META[macro];

  const data = React.useMemo<ChartPoint[]>(
    () =>
      points.map((point) => ({
        date: point.date,
        value: point.logged ? point[macro] : null,
        meals: point.meals,
        daysLogged: point.days_logged,
      })),
    [points, macro],
  );

  const loggedCount = data.filter((point) => point.value !== null).length;

  if (loggedCount === 0) {
    return (
      <div
        className={cn(
          "flex h-52 items-center justify-center rounded-xl bg-muted/40 px-6 text-center sm:h-60",
          className,
        )}
      >
        <p className="text-[0.8125rem] text-muted-foreground">
          No {meta.label.toLowerCase()} logged in this period yet.
        </p>
      </div>
    );
  }

  // Dots stop helping past a fortnight of points and start crowding the line.
  const showDots = data.length <= 14;

  // A single logged day has no line to draw, so the dot has to be visible.
  const dotRadius = loggedCount === 1 ? 4.5 : 3.5;

  const values = data
    .map((point) => point.value)
    .filter((value): value is number => value !== null);

  // Keep the target line inside the plot even when intake never reached it,
  // otherwise the reference line is clipped and reads as absent.
  const upper = Math.max(...values, target ?? 0);

  return (
    <div className={cn("h-52 w-full sm:h-60", className)}>
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: -12 }}>
          {/* Recessive: horizontal rules only, no vertical lattice. */}
          <CartesianGrid
            horizontal
            vertical={false}
            stroke="var(--border)"
            strokeDasharray="3 3"
          />

          <XAxis
            dataKey="date"
            tickFormatter={(value: string) =>
              granularity === "week"
                ? formatShortDate(value)
                : data.length > 14
                  ? formatDayOfMonth(value)
                  : formatShortDate(value)
            }
            interval="preserveStartEnd"
            minTickGap={granularity === "week" ? 24 : 16}
            tick={{ fontSize: 11, fill: "var(--muted-foreground)" }}
            stroke="var(--border)"
            tickLine={false}
            axisLine={false}
            padding={{ left: 8, right: 8 }}
          />

          <YAxis
            domain={[0, Math.ceil((upper * 1.12) / 10) * 10 || 10]}
            tickFormatter={(value: number) =>
              macro === "calories" && value >= 1000
                ? `${Math.round(value / 100) / 10}k`
                : String(Math.round(value))
            }
            tick={{ fontSize: 11, fill: "var(--muted-foreground)" }}
            tickLine={false}
            axisLine={false}
            width={44}
          />

          {typeof target === "number" && target > 0 && (
            <ReferenceLine
              y={target}
              stroke="var(--muted-foreground)"
              strokeDasharray="5 4"
              strokeOpacity={0.7}
              label={{
                value: "Target",
                position: "insideTopRight",
                fontSize: 10,
                fill: "var(--muted-foreground)",
              }}
            />
          )}

          <Tooltip
            cursor={{ stroke: "var(--border)", strokeWidth: 1 }}
            content={(props) => (
              <TrendTooltip
                {...props}
                macro={macro}
                target={target}
                granularity={granularity}
              />
            )}
          />

          <Line
            type="monotone"
            dataKey="value"
            name={meta.label}
            stroke={meta.cssVar}
            strokeWidth={2}
            connectNulls={false}
            dot={
              showDots
                ? { r: dotRadius, fill: meta.cssVar, strokeWidth: 0 }
                : false
            }
            activeDot={{
              r: 5,
              fill: meta.cssVar,
              // A surface ring keeps the active dot legible where it sits on
              // top of the grid or the target line.
              stroke: "var(--card)",
              strokeWidth: 2,
            }}
            isAnimationActive={false}
          />
        </LineChart>
      </ResponsiveContainer>
    </div>
  );
}

interface TooltipProps {
  active?: boolean;
  /** Readonly to match what Recharts hands a custom tooltip. */
  payload?: readonly { payload?: unknown }[];
  macro: MacroKey;
  target?: number | null;
  granularity: "day" | "week";
}

function TrendTooltip({ active, payload, macro, target, granularity }: TooltipProps) {
  if (!active || !payload?.length) return null;

  const point = payload[0]?.payload as ChartPoint | undefined;

  if (!point) return null;
  const meta = MACRO_META[macro];

  const title =
    granularity === "week"
      ? `Week of ${formatShortDate(point.date)}`
      : formatLongDate(point.date);

  const subtitle =
    granularity === "week"
      ? `${formatWeekRange(point.date, shiftDays(point.date, 6))} · ${point.daysLogged ?? 0} of 7 days logged`
      : point.meals === 0
        ? "Nothing logged"
        : `${point.meals} meal${point.meals === 1 ? "" : "s"}`;

  const format = (value: number) =>
    macro === "calories"
      ? `${formatCalories(value)} kcal`
      : formatMacro(value, meta.unit);

  return (
    <ChartTooltipCard title={title} subtitle={subtitle}>
      {point.value === null ? (
        <p className="text-[0.75rem] text-muted-foreground">No meals logged.</p>
      ) : (
        <>
          <ChartTooltipRow
            color={meta.cssVar}
            label={granularity === "week" ? `${meta.label} / day` : meta.label}
            value={format(point.value)}
          />
          {typeof target === "number" && target > 0 && (
            <ChartTooltipRow
              label="vs target"
              value={`${point.value >= target ? "+" : "−"}${format(Math.abs(point.value - target))}`}
            />
          )}
        </>
      )}
    </ChartTooltipCard>
  );
}
