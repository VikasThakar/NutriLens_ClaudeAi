import { api } from "@/lib/api-client";
import type {
  AnalyzeMealResponse,
  DataEnvelope,
  Meal,
  MealListParams,
  MealTip,
  MessageEnvelope,
  PaginatedEnvelope,
  SmartPlateAnalysis,
  SmartPlateInput,
  StoreMealInput,
  TodaySummary,
  UpdateMealInput,
} from "@/types/api";

/** POST /meals also returns a computed NutriLens Tip alongside the meal. */
type CreateMealResponse = MessageEnvelope<Meal> & { tip: MealTip };

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
    return api.post<CreateMealResponse>("/meals", input);
  },

  /**
   * The NutriLens Tip for one meal — how it sits against the day's remaining
   * targets. Computed server-side from the user's own figures, so this costs
   * no AI call.
   */
  tip(id: number) {
    return api.get<DataEnvelope<MealTip>>(`/meals/${id}/tip`);
  },

  /**
   * Smart Plate: how an **unsaved** meal fits the rest of today, and concrete
   * ways to improve it.
   *
   * Stateless — the draft goes up, an analysis comes back, nothing is written —
   * so it can run on every meaningful edit of the review screen.
   */
  smartPlate(input: SmartPlateInput) {
    return api.post<DataEnvelope<SmartPlateAnalysis>>("/meals/smart-plate", input);
  },

  update(id: number, input: UpdateMealInput) {
    return api.put<MessageEnvelope<Meal>>(`/meals/${id}`, input);
  },

  remove(id: number) {
    return api.delete<{ message: string }>(`/meals/${id}`);
  },
};
