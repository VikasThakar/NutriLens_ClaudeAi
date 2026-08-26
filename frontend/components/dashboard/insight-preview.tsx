import Link from "next/link";
import { ArrowRight, Sparkles } from "lucide-react";

import { cn } from "@/lib/utils";
import { formatWeekRange } from "@/lib/dates";
import { Button } from "@/components/ui/button";
import type { WeeklyInsight } from "@/types/api";

/**
 * The latest weekly summary, trimmed to a headline and its first line.
 *
 * When there is no summary yet this becomes the prompt to generate one — the
 * dashboard should never show a dead card.
 */
export function InsightPreview({
  insight,
  className,
}: {
  insight: WeeklyInsight | null;
  className?: string;
}) {
  return (
    <section
      className={cn(
        "flex flex-col rounded-2xl bg-card p-5 ring-1 ring-foreground/10 sm:p-6",
        className,
      )}
    >
      <div className="flex items-start justify-between gap-4">
        <div className="min-w-0">
          <p className="text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase">
            {insight?.week_start && insight.week_end
              ? `Weekly insight · ${formatWeekRange(insight.week_start, insight.week_end)}`
              : "Weekly insight"}
          </p>

          <h2 className="mt-1.5 font-heading text-base font-semibold">
            {insight?.headline ?? "No summary yet"}
          </h2>

          <p className="mt-1.5 line-clamp-3 text-[0.8125rem] leading-relaxed text-muted-foreground">
            {insight?.summary ??
              "Once you have logged a few days in a week, NutriLens can write a short read on how it went — using only your own totals."}
          </p>
        </div>

        <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/12 text-primary">
          <Sparkles className="size-5" />
        </span>
      </div>

      <Button
        render={<Link href="/insights" />}
        variant="ghost"
        size="sm"
        className="mt-4 self-start"
      >
        {insight ? "Read the full summary" : "Go to Insights"}
        <ArrowRight />
      </Button>
    </section>
  );
}
