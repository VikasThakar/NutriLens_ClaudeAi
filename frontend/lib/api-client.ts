import { env } from "@/lib/env";
import { clearToken, getToken } from "@/lib/auth-storage";
import type { ValidationErrors } from "@/types/api";

/**
 * The one place in the app that talks to the network.
 *
 * Feature code never calls `fetch` directly — it goes through the typed
 * services in `services/`, which are built on top of this client.
 */

export class ApiError extends Error {
  readonly status: number;
  readonly errors: ValidationErrors;
  /**
   * The full decoded error body. Some endpoints attach useful data to a
   * failure — /meals/analyze returns the stored image so the user can still
   * save the meal manually after the AI fails.
   */
  readonly payload: Record<string, unknown>;

  constructor(
    status: number,
    message: string,
    errors: ValidationErrors = {},
    payload: Record<string, unknown> = {},
  ) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.errors = errors;
    this.payload = payload;
  }

  /** Whether the server said retrying could work. Defaults to true. */
  get retryable(): boolean {
    return this.payload.retryable !== false;
  }

  get isNetworkError(): boolean {
    return this.status === 0;
  }

  /** True for a Laravel 422 validation failure. */
  get isValidation(): boolean {
    return this.status === 422;
  }

  get isUnauthenticated(): boolean {
    return this.status === 401;
  }

  /** First error message for a field, if the API reported one. */
  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0];
  }
}

export interface RequestOptions extends Omit<RequestInit, "body" | "method"> {
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  body?: unknown;
  /** Skip attaching the bearer token (used by login/register). */
  skipAuth?: boolean;
  /**
   * Explicit token — lets server-side callers pass one in, since they cannot
   * read the browser cookie.
   */
  token?: string | null;
  /** Query string parameters, appended when defined. */
  query?: Record<string, string | number | boolean | undefined | null>;
}

function buildUrl(path: string, query?: RequestOptions["query"]): string {
  const url = new URL(
    `${env.apiUrl}/${path.replace(/^\/+/, "")}`.replace(/([^:]\/)\/+/g, "$1"),
  );

  if (query) {
    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== null && value !== "") {
        url.searchParams.set(key, String(value));
      }
    }
  }

  return url.toString();
}

const NETWORK_ERROR_MESSAGE =
  "Could not reach the NutriLens API. Make sure the Laravel server is running.";

export async function apiFetch<T>(
  path: string,
  options: RequestOptions = {},
): Promise<T> {
  const { method = "GET", body, skipAuth, token, query, headers, ...rest } = options;

  // FormData must go out untouched: the browser sets a multipart Content-Type
  // with the boundary, and setting it ourselves would break the upload.
  const isFormData = typeof FormData !== "undefined" && body instanceof FormData;

  const requestHeaders = new Headers({
    Accept: "application/json",
    ...(body !== undefined && !isFormData
      ? { "Content-Type": "application/json" }
      : {}),
  });

  if (headers) {
    new Headers(headers).forEach((value, key) => requestHeaders.set(key, value));
  }

  if (!skipAuth) {
    const bearer = token ?? getToken();
    if (bearer) {
      requestHeaders.set("Authorization", `Bearer ${bearer}`);
    }
  }

  let response: Response;

  try {
    response = await fetch(buildUrl(path, query), {
      ...rest,
      method,
      headers: requestHeaders,
      body:
        body === undefined
          ? undefined
          : isFormData
            ? (body as FormData)
            : JSON.stringify(body),
      cache: "no-store",
    });
  } catch {
    throw new ApiError(0, NETWORK_ERROR_MESSAGE);
  }

  if (response.status === 204) {
    return undefined as T;
  }

  const raw = await response.text();
  let payload: unknown = null;

  if (raw) {
    try {
      payload = JSON.parse(raw);
    } catch {
      payload = null;
    }
  }

  if (!response.ok) {
    const data = (payload ?? {}) as { message?: string; errors?: ValidationErrors };

    // A rejected token is dead — drop it so the UI can fall back to signed-out.
    if (response.status === 401 && !skipAuth) {
      clearToken();
    }

    throw new ApiError(
      response.status,
      data.message ?? defaultMessageFor(response.status),
      data.errors ?? {},
      (payload ?? {}) as Record<string, unknown>,
    );
  }

  return payload as T;
}

function defaultMessageFor(status: number): string {
  switch (status) {
    case 401:
      return "Your session has expired. Please sign in again.";
    case 403:
      return "You do not have permission to do that.";
    case 404:
      return "We could not find what you were looking for.";
    case 429:
      return "Too many attempts. Please wait a moment and try again.";
    default:
      return status >= 500
        ? "Something went wrong on our end. Please try again."
        : "That request could not be completed.";
  }
}

export const api = {
  get: <T>(path: string, options?: RequestOptions) =>
    apiFetch<T>(path, { ...options, method: "GET" }),
  post: <T>(path: string, body?: unknown, options?: RequestOptions) =>
    apiFetch<T>(path, { ...options, method: "POST", body }),
  put: <T>(path: string, body?: unknown, options?: RequestOptions) =>
    apiFetch<T>(path, { ...options, method: "PUT", body }),
  patch: <T>(path: string, body?: unknown, options?: RequestOptions) =>
    apiFetch<T>(path, { ...options, method: "PATCH", body }),
  delete: <T>(path: string, options?: RequestOptions) =>
    apiFetch<T>(path, { ...options, method: "DELETE" }),
};