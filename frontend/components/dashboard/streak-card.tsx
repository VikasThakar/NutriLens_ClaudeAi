import { Flame, Trophy } from "lucide-react";

import { cn } from "@/lib/utils";
import { formatDayLabel, formatWeekday, isToday } from "@/lib/dates";
import type { StreakSummary } from "@/types/api";

/**
 * The logging streak.
 *
 * Motivating without overclaiming: it says exactly what a day means, and when
 * there is no streak it frames that as "start one today" rather than as a
 * failure. The activity strip is the honest version of the number — you can see
 * the gaps.
 */
export function StreakCard({
  streak,
  className,
}: {
  streak: StreakSummary;
  className?: string;
}) {
  const { current, longest, logged_today: loggedToday } = streak;
  // Show the recent strip a week at a time on small screens.
  const recent = streak.recent.slice(-14);

  return (
    <section
      className={cn(
        "rounded-2xl bg-card p-5 ring-1 ring-foreground/10 sm:p-6",
        className,
      )}
    >
      <div className="flex items-start justify-between gap-4">
        <div className="min-w-0">
          <p className="text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase">
            Logging streak
          </p>

          <p className="mt-1.5 flex items-baseline gap-2">
            <span className="font-heading text-3xl font-semibold tabular-nums">
              {current}
            </span>
            <span className="text-sm font-medium text-muted-foreground">
              {current === 1 ? "day" : "days"}
            </span>
          </p>

          <p className="mt-1 text-[0.8125rem] leading-relaxed text-muted-foreground">
            {current === 0
              ? "Log any meal today to start a streak."
              : loggedToday
                ? "Today is counted. A day counts once, however many meals are on it."
                : "Log something today to keep it going."}
          </p>
        </div>

        <span
          className={cn(
            "flex size-11 shrink-0 items-center justify-center rounded-xl transition-colors",
            current > 0
              ? "bg-primary/12 text-primary"
              : "bg-muted text-muted-foreground",
          )}
        >
          <Flame className="size-5" />
        </span>
      </div>

      {/* Recent activity */}
      <div className="mt-5">
        <div className="flex items-center justify-between">
          <p className="text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase">
            Last 14 days
          </p>
          {longest > 0 && (
            <p className="flex items-center gap-1.5 text-[0.6875rem] text-muted-foreground">
              <Trophy className="size-3.5" />
              Best: <span className="font-semibold tabular-nums">{longest}</span>{" "}
              {longest === 1 ? "day" : "days"}
            </p>
          )}
        </div>

        <ol className="mt-2.5 flex gap-1" aria-label="Recent logging activity">
          {recent.map((day) => (
            <li
              key={day.date}
              className="flex flex-1 flex-col items-center gap-1"
              title={`${formatDayLabel(day.date)}: ${day.logged ? "logged" : "nothing logged"}`}
            >
              <span
                className={cn(
                  "h-7 w-full rounded-md transition-colors",
                  day.logged
                    ? "bg-primary"
                    : "bg-muted ring-1 ring-inset ring-foreground/5",
                  isToday(day.date) && "ring-2 ring-primary/60",
                )}
              >
                <span className="sr-only">
                  {formatDayLabel(day.date)}:{" "}
                  {day.logged ? "logged" : "nothing logged"}
                </span>
              </span>
              <span
                className={cn(
                  "text-[0.5625rem] font-medium",
                  isToday(day.date) ? "text-foreground" : "text-muted-foreground",
                )}
              >
                {formatWeekday(day.date, "narrow")}
              </span>
            </li>
          ))}
        </ol>
      </div>
    </section>
  );
}
