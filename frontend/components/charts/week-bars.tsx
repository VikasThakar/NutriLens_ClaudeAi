"use client";

import * as React from "react";

import { cn } from "@/lib/utils";
import { formatDayLabel, formatWeekday, isToday } from "@/lib/dates";
import { formatCalories } from "@/lib/nutrition";
import type { DailyNutritionPoint } from "@/types/api";

/**
 * A compact seven-day calorie preview for the dashboard.
 *
 * Bars rather than a line: seven discrete days is a magnitude comparison, and
 * a bar makes "nothing logged" visible as an empty track instead of a gap the
 * eye interpolates across. Built from plain elements rather than a charting
 * library — at this size the library is all cost and no benefit.
 *
 * Each bar is a button so the hover layer works by tap as well as by pointer.
 */
export function WeekBars({
  points,
  target,
  className,
}: {
  points: DailyNutritionPoint[];
  target?: number | null;
  className?: string;
}) {
  const [activeIndex, setActiveIndex] = React.useState<number | null>(null);

  const peak = Math.max(...points.map((point) => point.calories), target ?? 0, 1);
  const active = activeIndex === null ? null : points[activeIndex];

  // The caption doubles as the hover layer: one line, above the bars, so no
  // floating tooltip has to be positioned inside a small card.
  const caption = active
    ? `${formatDayLabel(active.date)} · ${
        active.logged ? `${formatCalories(active.calories)} kcal` : "Nothing logged"
      }`
    : typeof target === "number" && target > 0
      ? `Dashed line is your ${formatCalories(target)} kcal target`
      : "Calories logged per day";

  return (
    <div className={cn("space-y-2.5", className)}>
      <p className="h-4 text-[0.6875rem] text-muted-foreground tabular-nums">
        {caption}
      </p>

      <div
        className="relative flex h-24 items-end gap-1.5"
        onPointerLeave={() => setActiveIndex(null)}
      >
        {typeof target === "number" && target > 0 && (
          <span
            aria-hidden="true"
            className="pointer-events-none absolute inset-x-0 border-t border-dashed border-muted-foreground/50"
            style={{ bottom: `${Math.min(100, (target / peak) * 100)}%` }}
          />
        )}

        {points.map((point, index) => {
          const height = point.logged
            ? Math.max(6, (point.calories / peak) * 100)
            : 0;
          const isActive = activeIndex === index;

          return (
            <button
              key={point.date}
              type="button"
              onPointerEnter={() => setActiveIndex(index)}
              onFocus={() => setActiveIndex(index)}
              onBlur={() => setActiveIndex(null)}
              onClick={() => setActiveIndex(index)}
              aria-label={`${formatDayLabel(point.date)}: ${
                point.logged
                  ? `${formatCalories(point.calories)} kilocalories`
                  : "nothing logged"
              }`}
              className="group relative flex h-full flex-1 cursor-default flex-col justify-end rounded-md focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
            >
              {/* Empty track, so an unlogged day is visibly a gap rather than absent. */}
              <span
                aria-hidden="true"
                className="absolute inset-x-0 bottom-0 h-full rounded-md bg-muted/50"
              />
              <span
                aria-hidden="true"
                className={cn(
                  "relative w-full rounded-md transition-[height,opacity] duration-500 ease-out",
                  point.logged ? "bg-calories" : "bg-transparent",
                  isActive ? "opacity-100" : "opacity-85",
                )}
                style={{ height: `${height}%` }}
              />
            </button>
          );
        })}
      </div>

      <div className="flex gap-1.5">
        {points.map((point) => (
          <span
            key={point.date}
            className={cn(
              "flex-1 text-center text-[0.625rem] font-medium",
              isToday(point.date)
                ? "text-foreground"
                : "text-muted-foreground",
            )}
          >
            {formatWeekday(point.date, "narrow")}
          </span>
        ))}
      </div>
    </div>
  );
}
