import { Sparkles } from "lucide-react";

import { cn } from "@/lib/utils";

/**
 * The one-line notice on any AI-derived meal.
 *
 * This is deliberately the *only* place uncertainty is surfaced in the meal UI.
 * Per-item confidence percentages and low-confidence warnings used to appear
 * alongside every food item and were removed: on a screen whose whole purpose is
 * "check these numbers and correct them", a chip saying "Estimated 72%" on each
 * row added noise without telling anyone what to do about it. One honest
 * sentence, once, says the same thing better.
 *
 * The underlying confidence values are still analysed, stored and returned by
 * the API — they are simply not rendered.
 */
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
