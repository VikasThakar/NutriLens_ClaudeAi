"use client";

import { cn } from "@/lib/utils";
import type { AnalyticsRange } from "@/types/api";

export const RANGE_OPTIONS: {
  value: AnalyticsRange;
  label: string;
  /** Screen-reader / tooltip wording, since the labels are abbreviated. */
  description: string;
}[] = [
  { value: "week", label: "7 days", description: "The last 7 days" },
  { value: "month", label: "30 days", description: "The last 30 days" },
  { value: "quarter", label: "3 months", description: "The last 90 days, by week" },
  { value: "year", label: "1 year", description: "The last 365 days, by week" },
];

/**
 * The time-range filter. One row above the charts, full width on mobile with
 * 44px-tall targets so it is comfortable to thumb.
 */
export function RangeTabs({
  value,
  onChange,
  disabled,
}: {
  value: AnalyticsRange;
  onChange: (range: AnalyticsRange) => void;
  disabled?: boolean;
}) {
  return (
    <div
      role="radiogroup"
      aria-label="Time range"
      className="flex w-full gap-1 rounded-xl bg-muted p-1 sm:w-auto"
    >
      {RANGE_OPTIONS.map((option) => {
        const active = option.value === value;

        return (
          <button
            key={option.value}
            type="button"
            role="radio"
            aria-checked={active}
            aria-label={option.description}
            disabled={disabled}
            onClick={() => onChange(option.value)}
            className={cn(
              // px-2 on mobile: four labels at px-3 can just exceed a 320px
              // viewport once the container padding is counted.
              "h-10 flex-1 rounded-lg px-2 text-[0.8125rem] font-medium whitespace-nowrap transition-colors sm:flex-none sm:px-3",
              "focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
              "disabled:opacity-60",
              active
                ? "bg-card text-foreground shadow-sm ring-1 ring-foreground/10"
                : "text-muted-foreground hover:text-foreground",
            )}
          >
            {option.label}
          </button>
        );
      })}
    </div>
  );
}
