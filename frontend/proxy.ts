import { NextResponse, type NextRequest } from "next/server";

import { AUTH_COOKIE } from "@/lib/auth-storage";

/**
 * First line of route protection (the Next.js 16 `proxy` convention, formerly
 * `middleware`). Runs on the server before a protected page is ever sent to the
 * browser.
 *
 * It only checks whether a token cookie *exists* — it cannot know whether that
 * token is still valid. `RequireAuth` handles the rest on the client, and the
 * Laravel API is the final authority (a bad token gets a 401 regardless).
 */
const PROTECTED_PREFIXES = [
  "/today",
  "/meals",
  "/add-meal",
  "/history",
  "/analytics",
  "/insights",
  "/developer",
  "/goals",
  "/settings",
  "/onboarding",
];

const GUEST_ONLY_PATHS = ["/login", "/register"];

export function proxy(request: NextRequest) {
  const { pathname } = request.nextUrl;
  const hasToken = Boolean(request.cookies.get(AUTH_COOKIE)?.value);

  const isProtected = PROTECTED_PREFIXES.some(
    (prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`),
  );

  if (isProtected && !hasToken) {
    const url = request.nextUrl.clone();
    url.pathname = "/login";
    url.search = "";
    return NextResponse.redirect(url);
  }

  if (hasToken && GUEST_ONLY_PATHS.includes(pathname)) {
    const url = request.nextUrl.clone();
    url.pathname = "/today";
    url.search = "";
    return NextResponse.redirect(url);
  }

  return NextResponse.next();
}

export const config = {
  // Skip Next internals and any path with a file extension (static assets).
  matcher: ["/((?!_next/static|_next/image|favicon.ico|.*\\.).*)"],
};
