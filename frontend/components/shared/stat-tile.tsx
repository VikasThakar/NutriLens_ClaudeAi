import type * as React from "react";

import { cn } from "@/lib/utils";

/**
 * One number with its label. Used wherever a figure is the point and a chart
 * would be overkill.
 *
 * The optional colour dot is decoration only — the label always names the
 * figure, so identity never depends on reading a hue.
 */
export function StatTile({
  label,
  value,
  unit,
  hint,
  dotColor,
  className,
}: {
  label: string;
  value: React.ReactNode;
  unit?: string;
  hint?: string;
  dotColor?: string;
  className?: string;
}) {
  return (
    <div
      className={cn(
        "rounded-xl bg-muted/50 px-3.5 py-3 ring-1 ring-foreground/5",
        className,
      )}
    >
      <p className="flex items-center gap-1.5 text-[0.6875rem] font-medium tracking-wide text-muted-foreground uppercase">
        {dotColor && (
          <span
            aria-hidden="true"
            className="size-2 shrink-0 rounded-full"
            style={{ backgroundColor: dotColor }}
          />
        )}
        <span className="truncate">{label}</span>
      </p>

      <p className="mt-1.5 font-heading text-lg font-semibold tabular-nums">
        {value}
        {unit && (
          <span className="ml-1 text-xs font-medium text-muted-foreground">
            {unit}
          </span>
        )}
      </p>

      {hint && (
        <p className="mt-0.5 text-[0.6875rem] leading-snug text-muted-foreground">
          {hint}
        </p>
      )}
    </div>
  );
}
