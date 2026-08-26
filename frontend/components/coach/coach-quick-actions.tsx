"use client";

import { ArrowUpRight } from "lucide-react";

import { cn } from "@/lib/utils";
import { COACH_QUICK_ACTIONS } from "@/lib/coach";

/**
 * The five starting questions.
 *
 * As cards while a conversation is empty — they are the main way in — and as a
 * single scrollable row of chips once there are messages, where they become a
 * shortcut rather than the point of the screen.
 */
export function CoachQuickActionCards({
  onSelect,
  disabled,
  className,
}: {
  onSelect: (prompt: string) => void;
  disabled?: boolean;
  className?: string;
}) {
  return (
    <div className={cn("grid gap-2.5 sm:grid-cols-2", className)}>
      {COACH_QUICK_ACTIONS.map((action) => (
        <button
          key={action.label}
          type="button"
          disabled={disabled}
          onClick={() => onSelect(action.prompt)}
          className={cn(
            "group flex items-center gap-3 rounded-xl bg-card p-3.5 text-left ring-1 ring-foreground/10 transition-colors",
            "hover:bg-accent/60 hover:ring-primary/25",
            "focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
            "disabled:pointer-events-none disabled:opacity-60",
            // The fifth card spans the row on desktop, so the grid never ends
            // on a lonely half-width card.
            "sm:last:col-span-2",
          )}
        >
          <span
            aria-hidden="true"
            className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-base"
          >
            {action.emoji}
          </span>
          <span className="min-w-0 flex-1 text-[0.875rem] font-medium">
            {action.label}
          </span>
          <ArrowUpRight className="size-4 shrink-0 text-muted-foreground transition-colors group-hover:text-primary" />
        </button>
      ))}
    </div>
  );
}

export function CoachQuickActionChips({
  onSelect,
  disabled,
  className,
}: {
  onSelect: (prompt: string) => void;
  disabled?: boolean;
  className?: string;
}) {
  return (
    <div
      className={cn(
        // Scrolls horizontally rather than wrapping: a wrapped row of five
        // chips would push the composer off a phone screen.
        "-mx-1 flex gap-2 overflow-x-auto px-1 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden",
        className,
      )}
    >
      {COACH_QUICK_ACTIONS.map((action) => (
        <button
          key={action.label}
          type="button"
          disabled={disabled}
          onClick={() => onSelect(action.prompt)}
          className={cn(
            "flex shrink-0 items-center gap-1.5 rounded-full bg-card px-3 py-1.5 text-xs font-medium ring-1 ring-foreground/10 transition-colors",
            "hover:bg-accent/60 hover:ring-primary/25",
            "focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
            "disabled:pointer-events-none disabled:opacity-60",
          )}
        >
          <span aria-hidden="true">{action.emoji}</span>
          {action.label}
        </button>
      ))}
    </div>
  );
}

/** Follow-up chips the coach itself offered, under its latest reply. */
export function CoachSuggestions({
  suggestions,
  onSelect,
  disabled,
}: {
  suggestions: string[];
  onSelect: (prompt: string) => void;
  disabled?: boolean;
}) {
  if (suggestions.length === 0) return null;

  return (
    <div className="mt-2.5 flex flex-wrap gap-2">
      {suggestions.map((suggestion) => (
        <button
          key={suggestion}
          type="button"
          disabled={disabled}
          onClick={() => onSelect(suggestion)}
          className={cn(
            "rounded-full border border-primary/25 bg-primary/8 px-3 py-1.5 text-xs font-medium text-primary transition-colors",
            "hover:bg-primary/15",
            "focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
            "disabled:pointer-events-none disabled:opacity-60",
          )}
        >
          {suggestion}
        </button>
      ))}
    </div>
  );
}
