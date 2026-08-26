import Link from "next/link";
import { ArrowLeft, type LucideIcon } from "lucide-react";

import { Button } from "@/components/ui/button";

/**
 * Honest placeholder for screens whose feature lands in a later phase. It says
 * what is coming rather than pretending to be a working dashboard.
 */
export function ComingSoon({
  icon: Icon,
  title,
  description,
  bullets,
}: {
  icon: LucideIcon;
  title: string;
  description: string;
  bullets: string[];
}) {
  return (
    <section className="relative overflow-hidden rounded-2xl bg-card p-6 ring-1 ring-foreground/10 sm:p-10">
      <div
        aria-hidden="true"
        className="brand-glow pointer-events-none absolute inset-0 opacity-60"
      />

      <div className="relative max-w-xl">
        <span className="flex size-12 items-center justify-center rounded-xl bg-primary/12 text-primary">
          <Icon className="size-5" />
        </span>

        <div className="mt-5 flex flex-wrap items-center gap-3">
          <h1 className="font-heading text-2xl font-semibold">{title}</h1>
          <span className="rounded-full bg-muted px-2 py-0.5 text-[0.625rem] font-semibold tracking-wide text-muted-foreground uppercase">
            Coming next
          </span>
        </div>

        <p className="mt-3 text-sm leading-relaxed text-muted-foreground">
          {description}
        </p>

        <ul className="mt-6 space-y-2.5">
          {bullets.map((bullet) => (
            <li key={bullet} className="flex items-start gap-2.5 text-sm">
              <span
                aria-hidden="true"
                className="mt-[0.4375rem] size-1.5 shrink-0 rounded-full bg-primary"
              />
              <span className="text-muted-foreground">{bullet}</span>
            </li>
          ))}
        </ul>

        <Button
          render={<Link href="/today" />}
          variant="outline"
          size="lg"
          className="mt-8"
        >
          <ArrowLeft />
          Back to Today
        </Button>
      </div>
    </section>
  );
}
