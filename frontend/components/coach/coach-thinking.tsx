"use client";

import * as React from "react";

import { COACH_THINKING_PHRASES } from "@/lib/coach";
import { LogoMark } from "@/components/layout/logo";

/** How long each phrase is shown before the next one. */
const PHRASE_MS = 2200;

/**
 * The coach's thinking state.
 *
 * Deliberately not a progress bar: we cannot know how long a provider will
 * take, and a bar that fills at an invented rate is a lie. Instead it names
 * the things the backend genuinely does — read today's totals, the recent
 * meals, the goal — and cycles while the request is in flight.
 */
export function CoachThinking() {
  const [index, setIndex] = React.useState(0);

  React.useEffect(() => {
    const timer = window.setInterval(() => {
      setIndex((current) => (current + 1) % COACH_THINKING_PHRASES.length);
    }, PHRASE_MS);

    return () => window.clearInterval(timer);
  }, []);

  return (
    <div className="flex gap-2.5">
      <span
        aria-hidden="true"
        className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-primary/12 text-primary"
      >
        <LogoMark className="size-[1.125rem]" />
      </span>

      <div
        className="flex items-center gap-2.5 rounded-2xl bg-card px-3.5 py-2.5 ring-1 ring-foreground/10"
        role="status"
        aria-live="polite"
      >
        <span aria-hidden="true" className="flex items-center gap-1">
          {[0, 1, 2].map((dot) => (
            <span
              key={dot}
              className="size-1.5 animate-bounce rounded-full bg-primary/70"
              style={{ animationDelay: `${dot * 140}ms`, animationDuration: "1s" }}
            />
          ))}
        </span>

        <span className="text-[0.8125rem] text-muted-foreground">
          {COACH_THINKING_PHRASES[index]}
        </span>
      </div>
    </div>
  );
}
