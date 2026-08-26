import { api } from "@/lib/api-client";
import type {
  CoachContext,
  CoachConversation,
  CoachReply,
  DataEnvelope,
  MessageEnvelope,
  PaginatedEnvelope,
} from "@/types/api";

export const coachService = {
  /**
   * The user's live nutrition context: today's targets, totals, remaining
   * macros, streak and a seven-day summary. No AI call — this is the same
   * object the coach itself is given, which is why the progress card on the
   * page can never show numbers the answers disagree with.
   */
  context() {
    return api.get<DataEnvelope<CoachContext>>("/ai-coach/context");
  },

  /** The user's chat threads, most recent activity first. */
  conversations(perPage = 20) {
    return api.get<PaginatedEnvelope<CoachConversation>>("/ai-coach/conversations", {
      query: { per_page: perPage },
    });
  },

  /** One thread with all of its messages. */
  conversation(id: number) {
    return api.get<DataEnvelope<CoachConversation>>(`/ai-coach/conversations/${id}`);
  },

  /**
   * Starts an empty thread. Called lazily — only when the first message of a
   * new chat is sent — so abandoning a "New chat" leaves nothing behind.
   */
  createConversation(title?: string) {
    return api.post<MessageEnvelope<CoachConversation>>("/ai-coach/conversations", {
      title,
    });
  },

  deleteConversation(id: number) {
    return api.delete<{ message: string }>(`/ai-coach/conversations/${id}`);
  },

  /**
   * Asks one question. The server rebuilds the nutrition context from scratch,
   * so the answer is never written against stale macros.
   */
  send(conversationId: number, message: string) {
    return api.post<DataEnvelope<CoachReply>>(
      `/ai-coach/conversations/${conversationId}/messages`,
      { message },
    );
  },
};
