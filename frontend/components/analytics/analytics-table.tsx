import { formatShortDate, formatWeekday } from "@/lib/dates";
import { MACRO_META, formatCalories, formatMacro } from "@/lib/nutrition";
import type { AnalyticsReport } from "@/types/api";

/**
 * The same numbers the charts draw, as a table.
 *
 * Not a nicety: the macro palette includes a hue whose contrast against a white
 * surface is below 3:1, and the accessibility rule for that is that the values
 * must also be readable as text somewhere. This is that somewhere — and it is
 * the fastest way to read an exact figure on a phone.
 */
export function AnalyticsTable({ report }: { report: AnalyticsReport }) {
  const isWeekly = report.range.granularity === "week";
  const rows = [...report.series].reverse();

  return (
    <div className="overflow-x-auto rounded-xl ring-1 ring-border">
      <table className="w-full min-w-[34rem] border-collapse text-left">
        <caption className="sr-only">
          Daily nutrition totals from {report.range.from} to {report.range.to}
        </caption>
        <thead>
          <tr className="border-b border-border bg-muted/50">
            <th
              scope="col"
              className="px-3 py-2.5 text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase"
            >
              {isWeekly ? "Week of" : "Date"}
            </th>
            {(["calories", "protein", "carbs", "fat"] as const).map((macro) => (
              <th
                key={macro}
                scope="col"
                className="px-3 py-2.5 text-right text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase"
              >
                {MACRO_META[macro].short}
              </th>
            ))}
            <th
              scope="col"
              className="px-3 py-2.5 text-right text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase"
            >
              Meals
            </th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr
              key={row.date}
              className="border-b border-border last:border-0 hover:bg-muted/30"
            >
              <th
                scope="row"
                className="px-3 py-2.5 text-[0.8125rem] font-medium whitespace-nowrap"
              >
                {formatShortDate(row.date)}
                {!isWeekly && (
                  <span className="ml-1.5 text-[0.6875rem] font-normal text-muted-foreground">
                    {formatWeekday(row.date)}
                  </span>
                )}
              </th>

              {row.logged ? (
                <>
                  <td className="px-3 py-2.5 text-right text-[0.8125rem] tabular-nums">
                    {formatCalories(row.calories)}
                  </td>
                  <td className="px-3 py-2.5 text-right text-[0.8125rem] tabular-nums">
                    {formatMacro(row.protein)}
                  </td>
                  <td className="px-3 py-2.5 text-right text-[0.8125rem] tabular-nums">
                    {formatMacro(row.carbs)}
                  </td>
                  <td className="px-3 py-2.5 text-right text-[0.8125rem] tabular-nums">
                    {formatMacro(row.fat)}
                  </td>
                  <td className="px-3 py-2.5 text-right text-[0.8125rem] tabular-nums">
                    {row.meals}
                  </td>
                </>
              ) : (
                <td
                  colSpan={5}
                  className="px-3 py-2.5 text-right text-[0.75rem] text-muted-foreground"
                >
                  Nothing logged
                </td>
              )}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
