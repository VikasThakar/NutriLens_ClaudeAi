import Link from "next/link";
import { BarChart3, Camera, Flame, Sparkles, Target } from "lucide-react";

import { Button } from "@/components/ui/button";

const WHAT_HAPPENS_NEXT = [
  {
    icon: Camera,
    title: "Photograph a meal",
    body: "The model identifies each item and estimates its calories, protein, carbohydrates and fat. You review and correct anything it got wrong.",
  },
  {
    icon: Flame,
    title: "A day counts once you log it",
    body: "One meal is enough to mark the day. Consecutive days build a streak.",
  },
  {
    icon: BarChart3,
    title: "Trends appear as you go",
    body: "Analytics is built entirely from your own meals, so it fills in over the first few days rather than showing you sample data.",
  },
  {
    icon: Sparkles,
    title: "A weekly read, after three days",
    body: "Once a week has three logged days, NutriLens can write a short summary of it from your own totals.",
  },
];

/**
 * The first thing a brand-new account sees.
 *
 * It sets expectations rather than apologising for being empty: the product has
 * nothing to show because the user has not logged anything, and this says what
 * will appear and when. Nothing here is fake data standing in for real data.
 */
export function FirstRun({ hasGoal }: { hasGoal: boolean }) {
  return (
    <div className="space-y-5">
      <section className="relative overflow-hidden rounded-2xl bg-card p-6 ring-1 ring-foreground/10 sm:p-10">
        <div
          aria-hidden="true"
          className="brand-glow pointer-events-none absolute inset-0 opacity-70"
        />

        <div className="relative">
          <span className="flex size-14 items-center justify-center rounded-2xl bg-primary/12 text-primary">
            <Camera className="size-6" />
          </span>

          <h2 className="mt-5 font-heading text-xl font-semibold sm:text-2xl">
            Log your first meal
          </h2>
          <p className="mt-2.5 max-w-xl text-sm leading-relaxed text-muted-foreground">
            NutriLens has nothing to show you yet, and it will not invent
            anything. Photograph your next meal and the dashboard, your streak
            and your trends all start from there.
          </p>

          <div className="mt-7 flex flex-col gap-3 sm:flex-row">
            <Button render={<Link href="/add-meal" />} size="xl">
              <Camera />
              Add Your First Meal
            </Button>
            {!hasGoal && (
              <Button
                render={<Link href="/goals" />}
                variant="outline"
                size="xl"
              >
                <Target />
                Set your targets
              </Button>
            )}
          </div>

          <ul className="mt-9 grid gap-5 sm:grid-cols-2">
            {WHAT_HAPPENS_NEXT.map((step) => (
              <li key={step.title} className="flex gap-3">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                  <step.icon className="size-4" />
                </span>
                <div className="min-w-0">
                  <p className="text-[0.8125rem] font-semibold">{step.title}</p>
                  <p className="mt-0.5 text-[0.75rem] leading-relaxed text-muted-foreground">
                    {step.body}
                  </p>
                </div>
              </li>
            ))}
          </ul>
        </div>
      </section>
    </div>
  );
}
