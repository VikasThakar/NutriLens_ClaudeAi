import { cn } from "@/lib/utils";
import { MACRO_META, formatMacro, progressPercent, rawPercent } from "@/lib/nutrition";
import type { MacroKey } from "@/types";

interface MacroBarProps {
  macro: Exclude<MacroKey, "calories">;
  consumed: number;
  target: number;
  className?: string;
}

/**
 * One macro's progress toward its daily target. Colour is semantic — protein
 * is always indigo, carbs amber, fat coral — so the bars are readable at a
 * glance without reading the labels.
 */
export function MacroBar({ macro, consumed, target, className }: MacroBarProps) {
  const meta = MACRO_META[macro];
  const percent = progressPercent(consumed, target);
  const over = rawPercent(consumed, target) > 100;

  return (
    <div className={cn("space-y-2", className)}>
      <div className="flex items-baseline justify-between gap-2">
        <span className="flex items-center gap-1.5 text-sm font-medium">
          <span
            aria-hidden="true"
            className="size-2 rounded-full"
            style={{ backgroundColor: meta.cssVar }}
          />
          {meta.short}
        </span>
        <span className="text-xs text-muted-foreground tabular-nums">
          <span className={cn("font-medium", over ? "text-destructive" : "text-foreground")}>
            {formatMacro(consumed, "")}
          </span>
          {" / "}
          {formatMacro(target, meta.unit)}
        </span>
      </div>

      <div
        className="h-2 w-full overflow-hidden rounded-full bg-muted"
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

/** Ultra-compact bar used inside the landing-page product preview. */
export function MacroChip({
  macro,
  value,
  percent,
}: {
  macro: Exclude<MacroKey, "calories">;
  value: string;
  percent: number;
}) {
  const meta = MACRO_META[macro];

  return (
    <div className="flex-1 space-y-1.5">
      <div className="flex items-baseline justify-between">
        <span className="text-[0.625rem] font-medium text-muted-foreground">
          {meta.short}
        </span>
        <span className="text-[0.625rem] font-semibold tabular-nums">{value}</span>
      </div>
      <div className="h-1.5 overflow-hidden rounded-full bg-muted">
        <div
          className="h-full rounded-full"
          style={{ width: `${percent}%`, backgroundColor: meta.cssVar }}
        />
      </div>
    </div>
  );
}
