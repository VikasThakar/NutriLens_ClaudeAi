import type * as React from "react";

import { RequireAuth } from "@/components/auth/require-auth";
import { AppBottomNav } from "@/components/layout/app-bottom-nav";
import { AppSidebar } from "@/components/layout/app-sidebar";
import { AppTopbar } from "@/components/layout/app-topbar";

/**
 * The authenticated shell: a sidebar on desktop, a top bar plus bottom
 * navigation on mobile. Everything inside requires a signed-in, onboarded user.
 */
export default function AppLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <RequireAuth mode="app">
      <div className="flex min-h-dvh flex-1 flex-col">
        <AppSidebar />

        <div className="flex flex-1 flex-col lg:pl-64">
          <AppTopbar />

          {/* pb accounts for the fixed bottom nav on mobile */}
          <main className="flex-1 px-4 pt-5 pb-28 sm:px-6 lg:px-8 lg:pt-8 lg:pb-12">
            <div className="mx-auto w-full max-w-5xl">{children}</div>
          </main>
        </div>

        <AppBottomNav />
      </div>
    </RequireAuth>
  );
}
