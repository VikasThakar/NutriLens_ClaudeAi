/**
 * Where the Sanctum bearer token lives on the client.
 *
 * A cookie (rather than localStorage) is used deliberately: Next.js middleware
 * runs on the server and can only gate protected routes if it can see the
 * token. The cookie is readable by JavaScript because the API client must
 * attach it as an `Authorization` header on cross-origin calls to Laravel.
 *
 * Production hardening note: to make this httpOnly you would proxy API calls
 * through Next.js route handlers instead of calling Laravel directly from the
 * browser. See README.md.
 */

export const AUTH_COOKIE = "nutrilens_token";

const MAX_AGE_SECONDS = 60 * 60 * 24 * 30; // 30 days

export function getToken(): string | null {
  if (typeof document === "undefined") return null;

  const match = document.cookie
    .split("; ")
    .find((row) => row.startsWith(`${AUTH_COOKIE}=`));

  if (!match) return null;

  const value = match.slice(AUTH_COOKIE.length + 1);
  return value ? decodeURIComponent(value) : null;
}

export function setToken(token: string): void {
  if (typeof document === "undefined") return;

  const secure = window.location.protocol === "https:" ? "; Secure" : "";

  document.cookie =
    `${AUTH_COOKIE}=${encodeURIComponent(token)}` +
    `; Path=/; Max-Age=${MAX_AGE_SECONDS}; SameSite=Lax${secure}`;
}

export function clearToken(): void {
  if (typeof document === "undefined") return;

  document.cookie = `${AUTH_COOKIE}=; Path=/; Max-Age=0; SameSite=Lax`;
}