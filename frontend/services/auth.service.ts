import { api } from "@/lib/api-client";
import type {
  AuthPayload,
  LoginInput,
  MessageEnvelope,
  RegisterInput,
} from "@/types/api";

const DEVICE_NAME = "nutrilens-web";

export const authService = {
  register(input: Omit<RegisterInput, "device_name">) {
    return api.post<MessageEnvelope<AuthPayload>>(
      "/register",
      { ...input, device_name: DEVICE_NAME },
      { skipAuth: true },
    );
  },

  login(input: Omit<LoginInput, "device_name">) {
    return api.post<MessageEnvelope<AuthPayload>>(
      "/login",
      { ...input, device_name: DEVICE_NAME },
      { skipAuth: true },
    );
  },

  logout() {
    return api.post<{ message: string }>("/logout");
  },
};