import { api } from "@/lib/api-client";
import type {
  AnalyticsRange,
  AnalyticsReport,
  DataEnvelope,
  StreakSummary,
} from "@/types/api";

export const analyticsService = {
  /**
   * Series and summary statistics over a range of the user's real logged
   * meals. Days with nothing logged come back as such rather than being
   * dropped, so the chart can show the gaps.
   */
  report(range: AnalyticsRange) {
    return api.get<DataEnvelope<AnalyticsReport>>("/analytics", {
      query: { range },
    });
  },

  /** An explicit window, for anything the fixed ranges do not cover. */
  reportBetween(from: string, to: string) {
    return api.get<DataEnvelope<AnalyticsReport>>("/analytics", {
      query: { from, to },
    });
  },

  streak() {
    return api.get<DataEnvelope<StreakSummary>>("/streak");
  },
};
