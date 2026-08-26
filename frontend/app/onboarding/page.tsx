import type { Metadata } from "next";

import { RequireAuth } from "@/components/auth/require-auth";
import { Logo } from "@/components/layout/logo";
import { ThemeToggle } from "@/components/layout/theme-toggle";
import { OnboardingFlow } from "@/components/onboarding/onboarding-flow";

export const metadata: Metadata = {
  title: "Set up your account",
  description: "Choose your goal and daily nutrition targets.",
};

export default function OnboardingPage() {
  return (
    <RequireAuth mode="onboarding">
      <div className="flex min-h-dvh flex-1 flex-col">
        <header className="flex items-center justify-between px-5 py-4 lg:px-8">
          <Logo href={null} />
          <ThemeToggle />
        </header>

        <main className="flex flex-1 items-center justify-center px-5 pt-4 pb-16 lg:px-8">
          <OnboardingFlow />
        </main>
      </div>
    </RequireAuth>
  );
}
