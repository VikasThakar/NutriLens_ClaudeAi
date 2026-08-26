"use client";

import * as React from "react";

import { cn } from "@/lib/utils";
import { mealsService } from "@/services";
import { Skeleton } from "@/components/ui/skeleton";
import type { MealTip } from "@/types/api";

const TONE_STYLES: Record<MealTip["tone"], { ring: string; accent: string }> = {
  positive: { ring: "ring-primary/25", accent: "text-primary" },
  neutral: { ring: "ring-foreground/10", accent: "text-muted-foreground" },
  caution: { ring: "ring-carbs/35", accent: "text-carbs" },
};

/**
 * The "NutriLens Tip" — one computed line about how a meal sits against the
 * day's remaining targets.
 *
 * Not an AI response: the backend works it out from the user's own figures, so
 * it is instant, free and exactly consistent with the numbers on the rest of
 * the screen. The emoji is decoration; the label carries the meaning.
 */
export function NutriLensTip({
  tip,
  className,
}: {
  tip: MealTip;
  className?: string;
}) {
  const tone = TONE_STYLES[tip.tone] ?? TONE_STYLES.neutral;

  return (
    <div
      className={cn(
        "rounded-xl bg-muted/50 px-3.5 py-3 ring-1",
        tone.ring,
        className,
      )}
    >
      <p className="flex items-center gap-1.5 text-[0.6875rem] font-semibold tracking-wide uppercase">
        <span aria-hidden="true">✨</span>
        <span className={tone.accent}>NutriLens Tip</span>
      </p>

      <p className="mt-1.5 text-[0.8125rem] font-semibold">{tip.headline}</p>
      <p className="mt-1 text-[0.8125rem] leading-relaxed text-muted-foreground">
        {tip.body}
      </p>
    </div>
  );
}

/**
 * The tip for one saved meal, fetched on demand.
 *
 * Fetched here rather than folded into the meal payload so that listing meals
 * never pays for a tip nobody is looking at. A failure is silent: a missing tip
 * is not worth an error message on a detail sheet.
 */
export function MealTipCard({
  mealId,
  className,
}: {
  mealId: number;
  className?: string;
}) {
  const [tip, setTip] = React.useState<MealTip | null>(null);
  const [loading, setLoading] = React.useState(true);

  React.useEffect(() => {
    let cancelled = false;

    void (async () => {
      try {
        const { data } = await mealsService.tip(mealId);
        if (!cancelled) setTip(data);
      } catch {
        if (!cancelled) setTip(null);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [mealId]);

  if (loading) {
    return <Skeleton className={cn("h-[5.25rem] rounded-xl", className)} />;
  }

  if (!tip) return null;

  return <NutriLensTip tip={tip} className={className} />;
}
