"use client";

import { cn } from "@/lib/utils";

/**
 * The card a chart tooltip renders inside. Kept separate from any one chart so
 * every tooltip in the product has the same surface, the same padding and the
 * same type scale.
 *
 * Text uses ink tokens, never the series colour — the colour lives on the swatch
 * beside it, so a low-contrast hue (amber on white) never has to be read as text.
 */
export function ChartTooltipCard({
  title,
  subtitle,
  children,
  className,
}: {
  title: string;
  subtitle?: string;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <div
      className={cn(
        "pointer-events-none min-w-40 rounded-xl bg-popover p-3 text-popover-foreground shadow-lg ring-1 ring-foreground/10",
        className,
      )}
    >
      <p className="text-[0.8125rem] font-semibold">{title}</p>
      {subtitle && (
        <p className="mt-0.5 text-[0.6875rem] text-muted-foreground">{subtitle}</p>
      )}
      <div className="mt-2 space-y-1.5">{children}</div>
    </div>
  );
}

export function ChartTooltipRow({
  color,
  label,
  value,
}: {
  color?: string;
  label: string;
  value: string;
}) {
  return (
    <div className="flex items-center gap-2 text-[0.75rem]">
      {color && (
        <span
          aria-hidden="true"
          className="size-2 shrink-0 rounded-full"
          style={{ backgroundColor: color }}
        />
      )}
      <span className="text-muted-foreground">{label}</span>
      <span className="ml-auto font-semibold tabular-nums">{value}</span>
    </div>
  );
}
