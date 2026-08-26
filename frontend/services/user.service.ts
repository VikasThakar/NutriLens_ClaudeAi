import { api } from "@/lib/api-client";
import type {
  DataEnvelope,
  MessageEnvelope,
  UpdateProfileInput,
  User,
} from "@/types/api";

export const userService = {
  /** The authenticated user plus their active nutrition goal. */
  me(token?: string | null) {
    return api.get<DataEnvelope<User>>("/user", { token });
  },

  updateProfile(input: UpdateProfileInput) {
    return api.patch<MessageEnvelope<User>>("/user", input);
  },
};