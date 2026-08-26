import { AlertTriangle, Sparkles } from "lucide-react";

import { cn } from "@/lib/utils";
import {
  CONFIDENCE_META,
  confidenceLevel,
  confidencePercent,
} from "@/lib/confidence";

export function ConfidenceBadge({
  value,
  showPercent = false,
  className,
}: {
  value: number | null | undefined;
  showPercent?: boolean;
  className?: string;
}) {
  if (value === null || value === undefined) return null;

  const level = confidenceLevel(value);
  const meta = CONFIDENCE_META[level];
  const percent = confidencePercent(value);

  return (
    <span
      className={cn(
        "inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-[0.6875rem] font-medium",
        meta.chipClass,
        className,
      )}
    >
      <span aria-hidden="true" className={cn("size-1.5 rounded-full", meta.dotClass)} />
      {meta.label}
      {showPercent && percent !== null && (
        <span className="opacity-70 tabular-nums">{percent}%</span>
      )}
    </span>
  );
}

/** The explanatory notice shown under a low-confidence item. */
export function LowConfidenceNotice({
  value,
  className,
}: {
  value: number | null | undefined;
  className?: string;
}) {
  const level = confidenceLevel(value);
  const meta = CONFIDENCE_META[level];

  if (!meta.hint) return null;

  return (
    <p
      className={cn(
        "flex items-start gap-2 rounded-lg bg-fat/10 px-2.5 py-2 text-[0.75rem] leading-relaxed text-fat",
        className,
      )}
    >
      <AlertTriangle className="mt-px size-3.5 shrink-0" />
      <span>{meta.hint}</span>
    </p>
  );
}

/** The always-present disclaimer on any AI-derived meal. */
export function AiDisclaimer({ className }: { className?: string }) {
  return (
    <p
      className={cn(
        "flex items-start gap-2 text-[0.75rem] leading-relaxed text-muted-foreground",
        className,
      )}
    >
      <Sparkles className="mt-px size-3.5 shrink-0 text-primary" />
      <span>Nutrition values are AI estimates and can be adjusted.</span>
    </p>
  );
}
