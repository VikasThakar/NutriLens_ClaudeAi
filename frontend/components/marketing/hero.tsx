import Link from "next/link";
import { ArrowRight, Camera, Sparkles } from "lucide-react";

import { Button } from "@/components/ui/button";
import { ProductPreview } from "@/components/marketing/product-preview";

const HIGHLIGHTS = [
  "Multiple items per photo",
  "Portion estimates you can edit",
  "Daily macro targets",
];

export function Hero() {
  return (
    <section className="relative overflow-hidden">
      {/* Restrained brand wash + fine grid, both masked so they fade out */}
      <div aria-hidden="true" className="brand-glow pointer-events-none absolute inset-0" />
      <div aria-hidden="true" className="grid-fade pointer-events-none absolute inset-0 opacity-40" />

      <div className="relative mx-auto w-full max-w-6xl px-5 pt-12 pb-16 sm:pt-16 lg:px-8 lg:pt-24 lg:pb-28">
        <div className="grid items-center gap-14 lg:grid-cols-[1.05fr_1fr] lg:gap-16">
          {/* Copy */}
          <div className="animate-rise mx-auto max-w-xl text-center lg:mx-0 lg:text-left">
            <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-card/70 px-3 py-1.5 text-xs font-medium text-muted-foreground backdrop-blur-sm">
              <Sparkles className="size-3.5 text-primary" />
              AI-powered macro tracking
            </span>

            <h1 className="mt-6 font-heading text-[2.5rem] leading-[1.05] font-semibold sm:text-5xl lg:text-[3.5rem]">
              Snap your food.
              <br />
              <span className="text-gradient-brand">See your nutrition.</span>
            </h1>

            <p className="mt-5 text-base leading-relaxed text-muted-foreground sm:text-lg">
              Upload or photograph your meal and let NutriLens estimate
              calories, protein, carbohydrates, fat and portions across every
              item on the plate. Review the results, adjust anything, and save
              it in seconds.
            </p>

            <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:justify-center lg:justify-start">
              <Button render={<Link href="/register" />} size="xl" className="group">
                Start Tracking
                <ArrowRight className="transition-transform duration-200 group-hover:translate-x-0.5" />
              </Button>
              <Button
                render={<a href="#how-it-works" />}
                variant="outline"
                size="xl"
              >
                <Camera />
                See How It Works
              </Button>
            </div>

            <ul className="mt-8 flex flex-wrap justify-center gap-x-5 gap-y-2 lg:justify-start">
              {HIGHLIGHTS.map((item) => (
                <li
                  key={item}
                  className="flex items-center gap-1.5 text-[0.8125rem] text-muted-foreground"
                >
                  <span aria-hidden="true" className="size-1.5 rounded-full bg-primary" />
                  {item}
                </li>
              ))}
            </ul>
          </div>

          {/* Product preview */}
          <div className="animate-fade relative lg:pl-4">
            <ProductPreview />
          </div>
        </div>
      </div>
    </section>
  );
}
