import Link from "next/link";
import { Camera, ImagePlus, Pencil } from "lucide-react";

import { Button } from "@/components/ui/button";

const ROUTES = [
  {
    icon: Camera,
    title: "Take a photo",
    body: "Point your camera at the plate and let NutriLens do the identifying.",
  },
  {
    icon: ImagePlus,
    title: "Upload an image",
    body: "Already have a photo? Drop it in and get the same breakdown.",
  },
  {
    icon: Pencil,
    title: "Enter it manually",
    body: "Know the numbers? Type the items and portions yourself.",
  },
];

/**
 * The first thing a new user sees on Today. It has to sell the next action, not
 * apologise for being empty.
 */
export function EmptyMeals() {
  return (
    <section className="relative overflow-hidden rounded-2xl bg-card p-6 text-center ring-1 ring-foreground/10 sm:p-10">
      <div
        aria-hidden="true"
        className="brand-glow pointer-events-none absolute inset-0 opacity-70"
      />

      <div className="relative mx-auto max-w-lg">
        <span className="mx-auto flex size-14 items-center justify-center rounded-2xl bg-primary/12 text-primary">
          <Camera className="size-6" />
        </span>

        <h2 className="mt-5 font-heading text-xl font-semibold sm:text-2xl">
          No meals logged yet today
        </h2>
        <p className="mt-2.5 text-sm leading-relaxed text-muted-foreground">
          Photograph your next meal and NutriLens will estimate the calories,
          protein, carbohydrates and fat for every item on the plate.
        </p>

        <Button
          render={<Link href="/add-meal" />}
          size="xl"
          className="mt-7 w-full sm:w-auto"
        >
          <Camera />
          Add Your First Meal
        </Button>

        <ul className="mt-9 grid gap-4 text-left sm:grid-cols-3">
          {ROUTES.map((route) => (
            <li key={route.title} className="flex gap-3 sm:flex-col sm:gap-2">
              <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                <route.icon className="size-4" />
              </span>
              <div>
                <p className="text-[0.8125rem] font-semibold">{route.title}</p>
                <p className="mt-0.5 text-[0.75rem] leading-relaxed text-muted-foreground">
                  {route.body}
                </p>
              </div>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}
