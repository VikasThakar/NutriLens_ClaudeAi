/**
 * Calendar-date helpers.
 *
 * Everything here works on `YYYY-MM-DD` strings, because that is what the API
 * speaks: the backend resolves a meal's day in the user's own timezone when it
 * is saved, and the client must not re-interpret it.
 *
 * The one trap this file exists to avoid: `new Date("2026-08-25")` is parsed as
 * **UTC** midnight, so in any negative-offset timezone it renders as the 24th.
 * Every parse below builds a local date from the parts instead.
 */

const ISO_DATE = /^(\d{4})-(\d{2})-(\d{2})$/;

/** Parse `YYYY-MM-DD` as local midnight. Invalid input yields today. */
export function parseISODate(iso: string): Date {
  const match = ISO_DATE.exec(iso);

  if (!match) return startOfLocalDay(new Date());

  return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
}

export function startOfLocalDay(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

/** A local `Date` as `YYYY-MM-DD` — never via toISOString(), which is UTC. */
export function toISODate(date: Date): string {
  const month = `${date.getMonth() + 1}`.padStart(2, "0");
  const day = `${date.getDate()}`.padStart(2, "0");

  return `${date.getFullYear()}-${month}-${day}`;
}

export function todayISO(): string {
  return toISODate(new Date());
}

/** `YYYY-MM` for the month a date falls in. */
export function monthOf(iso: string): string {
  return iso.slice(0, 7);
}

export function shiftDays(iso: string, delta: number): string {
  const date = parseISODate(iso);
  date.setDate(date.getDate() + delta);

  return toISODate(date);
}

export function shiftMonths(month: string, delta: number): string {
  const [year, monthNumber] = month.split("-").map(Number);
  const date = new Date(year, (monthNumber ?? 1) - 1 + delta, 1);

  return `${date.getFullYear()}-${`${date.getMonth() + 1}`.padStart(2, "0")}`;
}

export function isToday(iso: string): boolean {
  return iso === todayISO();
}

export function isFuture(iso: string): boolean {
  return iso > todayISO();
}

/** Whole days between two dates, positive when `to` is later. */
export function daysBetween(from: string, to: string): number {
  const ms = parseISODate(to).getTime() - parseISODate(from).getTime();

  return Math.round(ms / 86_400_000);
}

/* -------------------------------------------------------------------------
   Formatting
   ------------------------------------------------------------------------- */

/** "Today", "Yesterday", or "Mon 24 Aug". */
export function formatDayLabel(iso: string): string {
  const offset = daysBetween(todayISO(), iso);

  if (offset === 0) return "Today";
  if (offset === -1) return "Yesterday";
  if (offset === 1) return "Tomorrow";

  return parseISODate(iso).toLocaleDateString("en-US", {
    weekday: "short",
    month: "short",
    day: "numeric",
  });
}

/** "Monday, August 24" — with the year only when it is not the current one. */
export function formatLongDate(iso: string): string {
  const date = parseISODate(iso);
  const showYear = date.getFullYear() !== new Date().getFullYear();

  return date.toLocaleDateString("en-US", {
    weekday: "long",
    month: "long",
    day: "numeric",
    ...(showYear ? { year: "numeric" } : {}),
  });
}

/** "Aug 24" — chart axes and dense lists. */
export function formatShortDate(iso: string): string {
  return parseISODate(iso).toLocaleDateString("en-US", {
    month: "short",
    day: "numeric",
  });
}

/** "24" — the tightest axis tick, for a mobile chart. */
export function formatDayOfMonth(iso: string): string {
  return String(parseISODate(iso).getDate());
}

/** "Mon" — weekday initial-ish label for a seven-day strip. */
export function formatWeekday(iso: string, length: "short" | "narrow" = "short"): string {
  return parseISODate(iso).toLocaleDateString("en-US", { weekday: length });
}

/** "Aug 17 – 23" or "Aug 31 – Sep 6". */
export function formatWeekRange(startIso: string, endIso: string): string {
  const start = parseISODate(startIso);
  const end = parseISODate(endIso);

  const sameMonth = start.getMonth() === end.getMonth();

  const startLabel = start.toLocaleDateString("en-US", {
    month: "short",
    day: "numeric",
  });

  const endLabel = end.toLocaleDateString("en-US", {
    ...(sameMonth ? {} : { month: "short" }),
    day: "numeric",
  });

  return `${startLabel} – ${endLabel}`;
}

/** "August 2026" — a month heading. */
export function formatMonthLabel(month: string): string {
  const [year, monthNumber] = month.split("-").map(Number);

  return new Date(year, (monthNumber ?? 1) - 1, 1).toLocaleDateString("en-US", {
    month: "long",
    year: "numeric",
  });
}

/** The Monday of the week a date falls in. */
export function startOfWeekISO(iso: string): string {
  const date = parseISODate(iso);
  // getDay() is 0 for Sunday; shift so Monday is the first day.
  const offset = (date.getDay() + 6) % 7;
  date.setDate(date.getDate() - offset);

  return toISODate(date);
}
