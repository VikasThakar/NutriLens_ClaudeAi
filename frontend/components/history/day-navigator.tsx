"use client";

import * as React from "react";
import { CalendarDays, ChevronLeft, ChevronRight, SkipBack, SkipForward } from "lucide-react";

import { cn } from "@/lib/utils";
import { formatDayLabel, formatLongDate, isFuture, shiftDays, todayISO } from "@/lib/dates";
import { Button } from "@/components/ui/button";

/**
 * Date navigation for the History screen.
 *
 * Three ways to move, because they answer different questions: a step to the
 * adjacent calendar day, a jump to the nearest day that actually has meals
 * (which is what you want after a week off), and a date picker for anything
 * further afield.
 */
export function DayNavigator({
  date,
  onChange,
  previousLoggedDate,
  nextLoggedDate,
  busy,
}: {
  date: string;
  onChange: (next: string) => void;
  previousLoggedDate: string | null;
  nextLoggedDate: string | null;
  busy?: boolean;
}) {
  const inputId = React.useId();
  const today = todayISO();

  // Tomorrow can hold no meals, so stepping into it is never useful.
  const nextDay = shiftDays(date, 1);
  const canStepForward = !isFuture(nextDay);

  return (
    <section className="rounded-2xl bg-card p-3 ring-1 ring-foreground/10 sm:p-4">
      <div className="flex items-center gap-2">
        <Button
          variant="outline"
          size="icon"
          aria-label="Previous day"
          disabled={busy}
          onClick={() => onChange(shiftDays(date, -1))}
        >
          <ChevronLeft />
        </Button>

        <div className="min-w-0 flex-1 text-center">
          <p className="truncate font-heading text-[0.9375rem] font-semibold">
            {formatDayLabel(date)}
          </p>
          <p className="truncate text-[0.75rem] text-muted-foreground">
            {formatLongDate(date)}
          </p>
        </div>

        <Button
          variant="outline"
          size="icon"
          aria-label="Next day"
          disabled={busy || !canStepForward}
          onClick={() => onChange(nextDay)}
        >
          <ChevronRight />
        </Button>
      </div>

      <div className="mt-3 flex flex-wrap items-center justify-center gap-2">
        {/* A native date input: the platform picker is already touch-friendly,
            localised and accessible — a bespoke calendar would be worse. */}
        <label
          htmlFor={inputId}
          className={cn(
            "inline-flex h-9 items-center gap-2 rounded-lg bg-muted px-3 text-[0.8125rem] font-medium text-muted-foreground",
            "transition-colors hover:text-foreground focus-within:ring-3 focus-within:ring-ring/50",
          )}
        >
          <CalendarDays className="size-4" />
          <span className="sr-only sm:not-sr-only">Pick a date</span>
          <input
            id={inputId}
            type="date"
            value={date}
            max={today}
            disabled={busy}
            onChange={(event) => {
              if (event.target.value) onChange(event.target.value);
            }}
            className="w-[7.5rem] bg-transparent text-foreground outline-none"
          />
        </label>

        {previousLoggedDate && (
          <Button
            variant="ghost"
            size="sm"
            disabled={busy}
            onClick={() => onChange(previousLoggedDate)}
          >
            <SkipBack />
            {formatDayLabel(previousLoggedDate)}
          </Button>
        )}

        {nextLoggedDate && (
          <Button
            variant="ghost"
            size="sm"
            disabled={busy}
            onClick={() => onChange(nextLoggedDate)}
          >
            {formatDayLabel(nextLoggedDate)}
            <SkipForward />
          </Button>
        )}

        {date !== today && (
          <Button variant="ghost" size="sm" disabled={busy} onClick={() => onChange(today)}>
            Jump to today
          </Button>
        )}
      </div>
    </section>
  );
}
