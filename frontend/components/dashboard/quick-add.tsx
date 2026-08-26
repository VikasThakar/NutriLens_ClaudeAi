import Link from "next/link";
import { Camera, Pencil } from "lucide-react";

import { cn } from "@/lib/utils";

const ACTIONS = [
  {
    href: "/add-meal" as const,
    icon: Camera,
    title: "Snap or upload a photo",
    body: "Let the model read the plate and estimate every item",
  },
  {
    href: "/add-meal?mode=manual" as const,
    icon: Pencil,
    title: "Enter it manually",
    body: "Type the items and portions yourself",
  },
];

/**
 * Quick Add — both routes into the add-meal flow, one tap from the dashboard.
 * The manual link opens straight into the editor rather than making the user
 * dismiss the camera screen first.
 */
export function QuickAdd({ className }: { className?: string }) {
  return (
    <section
      className={cn(
        "rounded-2xl bg-card p-5 ring-1 ring-foreground/10 sm:p-6",
        className,
      )}
    >
      <h2 className="font-heading text-[0.9375rem] font-semibold">Quick add</h2>

      <ul className="mt-4 grid gap-2.5 sm:grid-cols-2">
        {ACTIONS.map((action) => (
          <li key={action.title}>
            <Link
              href={action.href}
              className={cn(
                "flex h-full items-center gap-3 rounded-xl bg-background p-3.5 ring-1 ring-border transition-all",
                "hover:ring-foreground/25 focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
              )}
            >
              <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/12 text-primary">
                <action.icon className="size-4" />
              </span>
              <span className="min-w-0">
                <span className="block text-[0.8125rem] font-semibold">
                  {action.title}
                </span>
                <span className="mt-0.5 block text-[0.75rem] leading-snug text-muted-foreground">
                  {action.body}
                </span>
              </span>
            </Link>
          </li>
        ))}
      </ul>
    </section>
  );
}
