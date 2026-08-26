import type * as React from "react";
import Link from "next/link";
import { ArrowLeft, Camera, Sparkles, Target } from "lucide-react";

import { Logo } from "@/components/layout/logo";
import { ThemeToggle } from "@/components/layout/theme-toggle";
import { RedirectIfAuthenticated } from "@/components/auth/redirect-if-authenticated";

const PROOF = [
  {
    icon: Camera,
    title: "One photo, the whole plate",
    body: "Every item is detected separately — not lumped into a single guess.",
  },
  {
    icon: Target,
    title: "Targets that fit your goal",
    body: "Calories and macros tuned to cutting, holding or building.",
  },
  {
    icon: Sparkles,
    title: "Weekly insights",
    body: "A short, specific read on what actually changed this week.",
  },
];

export default function AuthLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <div className="flex min-h-dvh flex-1 flex-col lg:grid lg:grid-cols-2">
      <RedirectIfAuthenticated />

      {/* Brand panel — desktop only */}
      <aside className="brand-glow relative hidden flex-col justify-between border-r border-border bg-muted/30 p-10 lg:flex xl:p-14">
        <Logo />

        <div className="max-w-md">
          <h2 className="font-heading text-3xl leading-tight font-semibold xl:text-[2.25rem]">
            Snap your food.
            <br />
            <span className="text-gradient-brand">See your nutrition.</span>
          </h2>
          <p className="mt-4 leading-relaxed text-muted-foreground">
            Tracking only works if it is fast. NutriLens turns a photograph into
            an editable, itemised nutrition breakdown.
          </p>

          <ul className="mt-10 space-y-6">
            {PROOF.map((item) => (
              <li key={item.title} className="flex gap-4">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/12 text-primary">
                  <item.icon className="size-[1.125rem]" />
                </span>
                <div>
                  <p className="text-sm font-semibold">{item.title}</p>
                  <p className="mt-1 text-sm leading-relaxed text-muted-foreground">
                    {item.body}
                  </p>
                </div>
              </li>
            ))}
          </ul>
        </div>

        <p className="text-xs text-muted-foreground">
          © {new Date().getFullYear()} NutriLens
        </p>
      </aside>

      {/* Form panel */}
      <main className="flex flex-1 flex-col">
        <div className="flex items-center justify-between gap-3 px-5 py-4 lg:px-8">
          <Link
            href="/"
            className="inline-flex items-center gap-1.5 rounded-lg text-sm font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
          >
            <ArrowLeft className="size-4" />
            <span className="lg:hidden">Back</span>
            <span className="hidden lg:inline">Back to site</span>
          </Link>
          <ThemeToggle />
        </div>

        <div className="flex flex-1 items-center justify-center px-5 pb-12 lg:px-8">
          <div className="w-full max-w-[26rem]">
            <div className="mb-8 lg:hidden">
              <Logo href={null} />
            </div>
            {children}
          </div>
        </div>
      </main>
    </div>
  );
}
