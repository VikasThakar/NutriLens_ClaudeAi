import { api } from "@/lib/api-client";
import type { ApiKeyListResponse, CreatedApiKey } from "@/types/api";

export const apiKeysService = {
  /** The caller's keys, newest first. Revoked keys are included. */
  list() {
    return api.get<ApiKeyListResponse>("/api-keys");
  },

  /**
   * Creates a key. The response is the only place the full key ever appears —
   * the server stores a hash and cannot reproduce it.
   */
  create(name: string) {
    return api.post<CreatedApiKey>("/api-keys", { name });
  },

  /** Revokes rather than deletes, so the row stays visible as revoked. */
  revoke(id: number) {
    return api.delete<{ message: string }>(`/api-keys/${id}`);
  },
};
