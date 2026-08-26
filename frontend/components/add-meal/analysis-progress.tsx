"use client";

import * as React from "react";
import { Check, Loader2, ScanSearch } from "lucide-react";

import { cn } from "@/lib/utils";

/**
 * The wait while Laravel uploads the photo and the model reads it.
 *
 * The real request is a single round trip, so there is no per-stage progress to
 * report. Rather than fake a percentage, the stages advance on a timer that is
 * paced to a typical analysis and then *holds* on the last one — so a slow
 * response looks like "still calculating", never like a stalled bar. The moment
 * the request lands, the parent unmounts this.
 */
const STAGES = [
  { label: "Uploading your meal", detail: "Sending the photo securely to NutriLens." },
  { label: "Identifying foods", detail: "Looking for each distinct item on the plate." },
  { label: "Estimating portions", detail: "Judging quantities against what's in frame." },
  { label: "Calculating nutrition", detail: "Working out calories and macros per item." },
] as const;

/** Milliseconds spent on each stage before advancing. */
const STAGE_DURATIONS = [900, 2600, 2600, 4000] as const;

export function AnalysisProgress({ slow = false }: { slow?: boolean }) {
  const [stage, setStage] = React.useState(0);

  React.useEffect(() => {
    if (stage >= STAGES.length - 1) return;

    const timer = window.setTimeout(
      () => setStage((current) => Math.min(current + 1, STAGES.length - 1)),
      STAGE_DURATIONS[stage],
    );

    return () => window.clearTimeout(timer);
  }, [stage]);

  return (
    <section
      aria-live="polite"
      aria-busy="true"
      className="relative overflow-hidden rounded-2xl bg-card p-6 ring-1 ring-foreground/10 sm:p-8"
    >
      <div
        aria-hidden="true"
        className="brand-glow pointer-events-none absolute inset-0 opacity-70"
      />

      <div className="relative">
        <div className="flex items-center gap-3">
          <span className="relative flex size-12 items-center justify-center rounded-2xl bg-primary/12 text-primary">
            <ScanSearch className="size-5" />
            <span className="absolute inset-0 animate-ping rounded-2xl bg-primary/15" />
          </span>
          <div>
            <h2 className="font-heading text-lg font-semibold">Analyzing your meal</h2>
            <p className="text-sm text-muted-foreground">
              This usually takes a few seconds.
            </p>
          </div>
        </div>

        <ol className="mt-7 space-y-1">
          {STAGES.map((item, index) => {
            const done = index < stage;
            const active = index === stage;

            return (
              <li
                key={item.label}
                className={cn(
                  "flex items-start gap-3 rounded-xl px-3 py-2.5 transition-colors duration-500",
                  active && "bg-muted/70",
                )}
              >
                <span
                  className={cn(
                    "mt-px flex size-5 shrink-0 items-center justify-center rounded-full transition-colors duration-500",
                    done && "bg-primary text-primary-foreground",
                    active && "bg-primary/15 text-primary",
                    !done && !active && "bg-muted text-muted-foreground",
                  )}
                >
                  {done ? (
                    <Check className="size-3" strokeWidth={3} />
                  ) : active ? (
                    <Loader2 className="size-3 animate-spin" />
                  ) : (
                    <span className="size-1.5 rounded-full bg-current opacity-40" />
                  )}
                </span>

                <span className="min-w-0">
                  <span
                    className={cn(
                      "block text-sm font-medium transition-colors duration-500",
                      done && "text-muted-foreground",
                      active && "text-foreground",
                      !done && !active && "text-muted-foreground/60",
                    )}
                  >
                    {item.label}
                  </span>
                  {active && (
                    <span className="mt-0.5 block text-[0.75rem] leading-relaxed text-muted-foreground">
                      {item.detail}
                    </span>
                  )}
                </span>
              </li>
            );
          })}
        </ol>

        {slow && (
          <p className="mt-5 rounded-lg bg-muted/70 px-3.5 py-3 text-[0.8125rem] leading-relaxed text-muted-foreground">
            This one is taking longer than usual — a busy model or a large photo.
            Hang on a little longer, or go back and try a smaller image.
          </p>
        )}
      </div>
    </section>
  );
}
