export type ConfidenceLevel = "high" | "estimated" | "review";

/**
 * Three bands, deliberately few. The point is to tell the user where to spend
 * their attention, not to surface a percentage they have to interpret.
 */
export function confidenceLevel(value: number | null | undefined): ConfidenceLevel {
  if (value === null || value === undefined) return "estimated";
  if (value >= 0.8) return "high";
  if (value >= 0.55) return "estimated";
  return "review";
}

export const CONFIDENCE_META: Record<
  ConfidenceLevel,
  {
    label: string;
    /** Shown when an item lands in this band and needs attention. */
    hint: string | null;
    dotClass: string;
    textClass: string;
    chipClass: string;
  }
> = {
  high: {
    label: "High confidence",
    hint: null,
    dotClass: "bg-primary",
    textClass: "text-primary",
    chipClass: "bg-primary/12 text-primary",
  },
  estimated: {
    label: "Estimated",
    hint: null,
    dotClass: "bg-carbs",
    textClass: "text-carbs",
    chipClass: "bg-carbs/15 text-carbs",
  },
  review: {
    label: "Needs review",
    hint: "The AI is less certain about this item. Review the estimate before saving.",
    dotClass: "bg-fat",
    textClass: "text-fat",
    chipClass: "bg-fat/15 text-fat",
  },
};

export function confidencePercent(value: number | null | undefined): number | null {
  if (value === null || value === undefined) return null;
  return Math.round(value * 100);
}

export const AI_DISCLAIMER =
  "Nutrition values are AI estimates and can be adjusted.";
