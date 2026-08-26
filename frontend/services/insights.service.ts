import { api } from "@/lib/api-client";
import type {
  CurrentWeekInsight,
  DataEnvelope,
  GenerateInsightResponse,
  PaginatedEnvelope,
  WeeklyInsight,
} from "@/types/api";

export const insightsService = {
  /** Previously generated summaries, newest week first. */
  list(perPage = 12) {
    return api.get<PaginatedEnvelope<WeeklyInsight>>("/insights", {
      query: { per_page: perPage },
    });
  },

  /**
   * The state of one week — its real aggregates, the stored summary if there
   * is one, and whether that summary still matches the data. No AI call.
   *
   * @param date any date inside the wanted week; omit for the current week
   */
  current(date?: string) {
    return api.get<DataEnvelope<CurrentWeekInsight>>("/insights/current", {
      query: { date },
    });
  },

  /**
   * Generate a summary, or return the stored one when the numbers behind it
   * have not changed. `force` asks for a fresh one regardless — only worth
   * offering once the underlying data has actually moved.
   */
  generate(options: { date?: string; force?: boolean } = {}) {
    return api.post<GenerateInsightResponse>("/insights/generate", {
      date: options.date,
      force: options.force ?? false,
    });
  },
};
