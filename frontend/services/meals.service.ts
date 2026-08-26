import { api } from "@/lib/api-client";
import type {
  AnalyzeMealResponse,
  DataEnvelope,
  Meal,
  MealListParams,
  MessageEnvelope,
  PaginatedEnvelope,
  StoreMealInput,
  TodaySummary,
  UpdateMealInput,
} from "@/types/api";

export const mealsService = {
  /**
   * Everything the Today dashboard renders for a single day: the active goal,
   * consumed/remaining macros, and the day's meals grouped by meal type.
   *
   * @param date optional YYYY-MM-DD; defaults to the user's today
   */
  today(date?: string) {
    return api.get<DataEnvelope<TodaySummary>>("/dashboard/today", {
      query: { date },
    });
  },

  /**
   * Send a photo to Laravel for AI analysis. The image is stored server-side
   * and returned with the draft, so a failed analysis does not cost the user
   * their photo.
   */
  analyze(file: File) {
    const form = new FormData();
    form.append("image", file);

    return api.post<MessageEnvelope<AnalyzeMealResponse>>("/meals/analyze", form);
  },

  list(params: MealListParams = {}) {
    return api.get<PaginatedEnvelope<Meal>>("/meals", { query: { ...params } });
  },

  get(id: number) {
    return api.get<DataEnvelope<Meal>>(`/meals/${id}`);
  },

  /** Saves both reviewed AI analyses and manually entered meals. */
  create(input: StoreMealInput) {
    return api.post<MessageEnvelope<Meal>>("/meals", input);
  },

  update(id: number, input: UpdateMealInput) {
    return api.put<MessageEnvelope<Meal>>(`/meals/${id}`, input);
  },

  remove(id: number) {
    return api.delete<{ message: string }>(`/meals/${id}`);
  },
};
