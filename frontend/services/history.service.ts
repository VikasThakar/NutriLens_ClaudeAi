import { api } from "@/lib/api-client";
import type { DataEnvelope, HistoryCalendar, HistoryDay } from "@/types/api";

export const historyService = {
  /**
   * One day of history: totals, the meals in the order they were eaten, and
   * the nearest logged days either side so navigation can skip empty stretches.
   *
   * @param date YYYY-MM-DD; omit for the user's today
   */
  day(date?: string) {
    return api.get<DataEnvelope<HistoryDay>>("/history/day", {
      query: { date },
    });
  },

  /**
   * Which days in a month have meals, and how much was on each.
   *
   * @param month YYYY-MM; omit for the current month
   */
  calendar(month?: string) {
    return api.get<DataEnvelope<HistoryCalendar>>("/history/calendar", {
      query: { month },
    });
  },
};
