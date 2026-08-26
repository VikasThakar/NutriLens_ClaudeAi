import { cn } from "@/lib/utils";
import { formatCalories, progressPercent, rawPercent } from "@/lib/nutrition";

interface CalorieRingProps {
  consumed: number;
  target: number;
  /** Outer diameter in pixels. */
  size?: number;
  strokeWidth?: number;
  className?: string;
  /** Compact variant drops the caption lines. */
  dense?: boolean;
}

/**
 * The primary "how is my day going" object: a single ring showing calories
 * consumed against the daily target, with the remaining figure at its centre.
 */
export function CalorieRing({
  consumed,
  target,
  size = 176,
  strokeWidth = 12,
  className,
  dense = false,
}: CalorieRingProps) {
  const radius = (size - strokeWidth) / 2;
  const circumference = 2 * Math.PI * radius;
  const percent = progressPercent(consumed, target);
  const over = rawPercent(consumed, target) > 100;
  const remaining = target - consumed;

  return (
    <div
      className={cn("relative shrink-0", className)}
      style={{ width: size, height: size }}
      role="img"
      aria-label={`${formatCalories(consumed)} of ${formatCalories(target)} calories consumed`}
    >
      <svg width={size} height={size} className="-rotate-90">
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke="var(--muted)"
          strokeWidth={strokeWidth}
        />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke={over ? "var(--destructive)" : "var(--macro-calories)"}
          strokeWidth={strokeWidth}
          strokeLinecap="round"
          strokeDasharray={circumference}
          strokeDashoffset={circumference - (percent / 100) * circumference}
          className="transition-[stroke-dashoffset] duration-700 ease-out"
        />
      </svg>

      <div className="absolute inset-0 flex flex-col items-center justify-center gap-0.5">
        <span
          className={cn(
            "font-heading font-semibold tabular-nums tracking-tight",
            dense ? "text-2xl" : "text-[2rem] leading-none",
          )}
        >
          {formatCalories(Math.max(0, remaining))}
        </span>
        {!dense && (
          <>
            <span className="text-xs font-medium text-muted-foreground">
              {over ? "over target" : "kcal left"}
            </span>
            <span className="mt-1 text-[0.6875rem] text-muted-foreground/80 tabular-nums">
              {formatCalories(consumed)} / {formatCalories(target)}
            </span>
          </>
        )}
      </div>
    </div>
  );
}
