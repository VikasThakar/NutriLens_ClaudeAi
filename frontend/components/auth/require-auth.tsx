"use client";

import * as React from "react";
import { useRouter } from "next/navigation";

import { useAuth } from "@/hooks/use-auth";
import { LogoMark } from "@/components/layout/logo";

function FullScreenLoader({ label }: { label: string }) {
  return (
    <div className="flex min-h-dvh flex-1 flex-col items-center justify-center gap-4">
      <LogoMark className="size-10 animate-pulse text-primary" />
      <p className="text-sm text-muted-foreground">{label}</p>
    </div>
  );
}

interface RequireAuthProps {
  children: React.ReactNode;
  /**
   * `app` — the authenticated shell: requires a signed-in, onboarded user.
   * `onboarding` — requires a signed-in user who has *not* onboarded yet.
   */
  mode?: "app" | "onboarding";
}

/**
 * Client-side route protection. Next.js middleware already blocks these paths
 * when no token cookie is present; this guard covers the cases middleware
 * cannot see — an expired or revoked token, and onboarding state.
 */
export function RequireAuth({ children, mode = "app" }: RequireAuthProps) {
  const router = useRouter();
  const { status, user } = useAuth();

  React.useEffect(() => {
    if (status === "loading") return;

    if (status === "unauthenticated" || !user) {
      router.replace("/login");
      return;
    }

    if (mode === "app" && !user.has_onboarded) {
      router.replace("/onboarding");
      return;
    }

    if (mode === "onboarding" && user.has_onboarded) {
      router.replace("/today");
    }
  }, [status, user, mode, router]);

  if (status === "loading") {
    return <FullScreenLoader label="Loading NutriLens…" />;
  }

  if (status === "unauthenticated" || !user) {
    return <FullScreenLoader label="Redirecting to sign in…" />;
  }

  // Hold the loader while the effect above navigates, so a half-valid state
  // never flashes the wrong screen.
  if (mode === "app" && !user.has_onboarded) {
    return <FullScreenLoader label="Finishing setup…" />;
  }

  if (mode === "onboarding" && user.has_onboarded) {
    return <FullScreenLoader label="Opening your dashboard…" />;
  }

  return <>{children}</>;
}
