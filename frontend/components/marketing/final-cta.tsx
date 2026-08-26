import Link from "next/link";
import { ArrowRight } from "lucide-react";

import { Button } from "@/components/ui/button";

export function FinalCta() {
  return (
    <section className="border-t border-border py-20 lg:py-28">
      <div className="mx-auto w-full max-w-6xl px-5 lg:px-8">
        <div className="brand-glow relative overflow-hidden rounded-3xl bg-card px-6 py-14 text-center ring-1 ring-foreground/10 elevate sm:px-12 lg:py-20">
          <div className="relative mx-auto max-w-2xl">
            <h2 className="font-heading text-3xl font-semibold sm:text-4xl">
              Start tracking in the time it takes to take a photo
            </h2>
            <p className="mt-4 text-base leading-relaxed text-muted-foreground sm:text-lg">
              Create your account, set your daily targets, and log your first
              meal. Free to start — no card, no setup call.
            </p>

            <div className="mt-9 flex flex-col gap-3 sm:flex-row sm:justify-center">
              <Button render={<Link href="/register" />} size="xl" className="group">
                Create your account
                <ArrowRight className="transition-transform duration-200 group-hover:translate-x-0.5" />
              </Button>
              <Button render={<Link href="/login" />} variant="outline" size="xl">
                I already have one
              </Button>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
