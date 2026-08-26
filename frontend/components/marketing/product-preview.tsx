import { Check, Sparkles } from "lucide-react";

import { cn } from "@/lib/utils";
import { CalorieRing } from "@/components/dashboard/calorie-ring";
import { MacroChip } from "@/components/dashboard/macro-bar";

/* The preview mirrors the real "review your analysis" screen: detected items
   with confidence, editable portions, and the resulting macro totals. */

const DETECTED = [
  { name: "Grilled chicken breast", portion: "165 g", kcal: 272, confidence: 94 },
  { name: "Brown rice", portion: "1 cup", kcal: 216, confidence: 89 },
  { name: "Avocado", portion: "½ fruit", kcal: 161, confidence: 91 },
  { name: "Olive oil dressing", portion: "1 tbsp", kcal: 119, confidence: 76 },
];

const TOTAL = DETECTED.reduce((sum, item) => sum + item.kcal, 0);

function ScanFrame({
  className,
  label,
  confidence,
}: {
  className?: string;
  label: string;
  confidence: number;
}) {
  return (
    <div className={cn("absolute rounded-lg ring-2 ring-white/80", className)}>
      <span className="absolute -top-2 left-1.5 flex -translate-y-full items-center gap-1 rounded-md bg-black/75 px-1.5 py-0.5 text-[0.5625rem] font-medium whitespace-nowrap text-white backdrop-blur-sm">
        {label}
        <span className="text-white/60">{confidence}%</span>
      </span>
    </div>
  );
}

/**
 * A stylised plate rendered entirely in CSS — no stock photography. Warm,
 * abstract shapes stand in for food so the analysis overlay is the hero.
 */
function PlateVisual() {
  return (
    <div className="absolute inset-0 overflow-hidden bg-[oklch(0.32_0.03_60)]">
      <div
        className="absolute inset-0"
        style={{
          backgroundImage: `
            radial-gradient(9rem 7rem at 32% 42%, oklch(0.78 0.13 78) 0%, transparent 62%),
            radial-gradient(7rem 6rem at 66% 34%, oklch(0.62 0.14 145) 0%, transparent 60%),
            radial-gradient(6rem 5rem at 58% 70%, oklch(0.7 0.14 40) 0%, transparent 62%),
            radial-gradient(11rem 9rem at 50% 55%, oklch(0.42 0.04 70) 0%, transparent 78%)
          `,
        }}
      />
      {/* Plate rim */}
      <div className="absolute inset-[8%] rounded-full ring-1 ring-white/12" />
      {/* Lens vignette */}
      <div className="absolute inset-0 bg-[radial-gradient(circle_at_50%_45%,transparent_35%,oklch(0_0_0/0.45)_100%)]" />
    </div>
  );
}

export function ProductPreview({ className }: { className?: string }) {
  return (
    <div className={cn("relative", className)}>
      {/* Primary device — the analysis review screen */}
      <div className="relative mx-auto w-full max-w-[20rem] rounded-[2rem] bg-card p-2 ring-1 ring-foreground/10 elevate-lg">
        <div className="overflow-hidden rounded-[1.5rem] bg-background ring-1 ring-foreground/[0.06]">
          {/* Photo + detection overlay */}
          <div className="relative aspect-[4/3.2] w-full">
            <PlateVisual />

            <ScanFrame
              className="top-[26%] left-[16%] h-[26%] w-[34%]"
              label="Chicken"
              confidence={94}
            />
            <ScanFrame
              className="top-[20%] left-[56%] h-[24%] w-[28%]"
              label="Rice"
              confidence={89}
            />
            <ScanFrame
              className="top-[58%] left-[44%] h-[24%] w-[26%]"
              label="Avocado"
              confidence={91}
            />

            <div className="absolute top-3 left-3 flex items-center gap-1.5 rounded-full bg-black/70 px-2.5 py-1 text-[0.625rem] font-medium text-white backdrop-blur-sm">
              <Sparkles className="size-3" />
              4 items detected
            </div>
          </div>

          {/* Detected items */}
          <div className="divide-y divide-border">
            {DETECTED.map((item) => (
              <div key={item.name} className="flex items-center gap-3 px-3.5 py-2.5">
                <div className="flex size-6 shrink-0 items-center justify-center rounded-md bg-primary/12 text-primary">
                  <Check className="size-3.5" />
                </div>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-[0.8125rem] leading-tight font-medium">
                    {item.name}
                  </p>
                  <p className="text-[0.6875rem] text-muted-foreground">
                    {item.portion} · {item.confidence}% confidence
                  </p>
                </div>
                <span className="text-[0.8125rem] font-semibold tabular-nums">
                  {item.kcal}
                </span>
              </div>
            ))}
          </div>

          {/* Totals */}
          <div className="space-y-3 border-t border-border bg-muted/40 px-3.5 py-3">
            <div className="flex items-baseline justify-between">
              <span className="text-[0.6875rem] font-medium tracking-wide text-muted-foreground uppercase">
                Meal total
              </span>
              <span className="font-heading text-lg font-semibold tabular-nums">
                {TOTAL.toLocaleString("en-US")}
                <span className="ml-1 text-xs font-medium text-muted-foreground">
                  kcal
                </span>
              </span>
            </div>
            <div className="flex gap-3">
              <MacroChip macro="protein" value="62g" percent={82} />
              <MacroChip macro="carbs" value="54g" percent={46} />
              <MacroChip macro="fat" value="38g" percent={61} />
            </div>
          </div>
        </div>
      </div>

      {/* Floating "Today" card — desktop only, so mobile stays uncluttered */}
      <div className="absolute -top-6 -right-4 hidden w-[13.5rem] rounded-2xl bg-card p-4 ring-1 ring-foreground/10 elevate-lg xl:block">
        <p className="text-[0.6875rem] font-medium tracking-wide text-muted-foreground uppercase">
          Today
        </p>
        <div className="mt-2 flex items-center gap-3">
          <CalorieRing consumed={1332} target={2100} size={64} strokeWidth={7} dense />
          <div className="min-w-0 space-y-1.5">
            <MacroChip macro="protein" value="98g" percent={70} />
            <MacroChip macro="carbs" value="142g" percent={64} />
          </div>
        </div>
      </div>

      {/* Floating weekly insight card */}
      <div className="absolute -bottom-8 -left-4 hidden w-[15rem] rounded-2xl bg-card p-4 ring-1 ring-foreground/10 elevate-lg xl:block">
        <div className="flex items-center gap-2">
          <span className="flex size-6 items-center justify-center rounded-md bg-primary/12 text-primary">
            <Sparkles className="size-3.5" />
          </span>
          <p className="text-[0.8125rem] font-semibold">Weekly insight</p>
        </div>
        <p className="mt-2 text-[0.75rem] leading-relaxed text-muted-foreground">
          Protein hit target on 6 of 7 days. Carbs run high on weekends — try
          front-loading them around training.
        </p>
      </div>
    </div>
  );
}
