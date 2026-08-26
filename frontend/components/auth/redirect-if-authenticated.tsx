"use client";

import * as React from "react";
import { useRouter } from "next/navigation";

import { useAuth } from "@/hooks/use-auth";

/**
 * Keeps signed-in users off the login / register screens. Renders nothing —
 * it exists purely for the redirect.
 */
export function RedirectIfAuthenticated() {
  const router = useRouter();
  const { status, user } = useAuth();

  React.useEffect(() => {
    if (status !== "authenticated" || !user) return;
    router.replace(user.has_onboarded ? "/today" : "/onboarding");
  }, [status, user, router]);

  return null;
}
