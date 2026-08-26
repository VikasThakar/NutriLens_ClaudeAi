import {
  CalendarRange,
  LayoutList,
  Moon,
  PencilLine,
  ScanFace,
  Sliders,
  Sparkles,
  Target,
} from "lucide-react";

const FEATURES = [
  {
    title: "AI Food Recognition",
    icon: ScanFace,
    description:
      "Point your camera at the plate. The model names what it sees instead of asking you to.",
  },
  {
    title: "Multi-item Meal Detection",
    icon: LayoutList,
    description:
      "A full meal is not one entry. Every component is detected and costed separately.",
  },
  {
    title: "Editable AI Results",
    icon: PencilLine,
    description:
      "The estimate is a starting point, never the final word. Rename, remove, or add items.",
  },
  {
    title: "Portion Adjustment",
    icon: Sliders,
    description:
      "Nudge a portion and every macro recalculates instantly across the meal.",
  },
  {
    title: "Daily Macro Goals",
    icon: Target,
    description:
      "Calories, protein, carbs and fat targets tuned to whether you are cutting, holding or building.",
  },
  {
    title: "Progress Tracking",
    icon: CalendarRange,
    description:
      "A complete history of every meal, with trends that show where the week actually went.",
  },
  {
    title: "Weekly AI Insights",
    icon: Sparkles,
    description:
      "A short, specific read on your week — what held, what slipped, what to change.",
  },
  {
    title: "Dark Mode",
    icon: Moon,
    description:
      "A genuine dark theme, not an inverted light one. Follows your system by default.",
  },
];

export function Features() {
  return (
    <section
      id="features"
      className="scroll-mt-20 border-t border-border bg-muted/30 py-20 lg:py-28"
    >
      <div className="mx-auto w-full max-w-6xl px-5 lg:px-8">
        <div className="max-w-2xl">
          <p className="text-sm font-semibold tracking-wide text-primary uppercase">
            Features
          </p>
          <h2 className="mt-3 font-heading text-3xl font-semibold sm:text-4xl">
            Built for people who actually log their food
          </h2>
          <p className="mt-4 text-base leading-relaxed text-muted-foreground">
            Tracking fails when it takes too long. Everything here exists to cut
            the time between finishing a meal and having it logged correctly.
          </p>
        </div>

        <div className="mt-12 grid gap-px overflow-hidden rounded-2xl bg-border ring-1 ring-border sm:grid-cols-2 lg:grid-cols-4">
          {FEATURES.map((feature) => (
            <div
              key={feature.title}
              className="group bg-card p-6 transition-colors duration-300 hover:bg-accent/40"
            >
              <span className="flex size-10 items-center justify-center rounded-lg bg-primary/12 text-primary transition-transform duration-300 group-hover:scale-105">
                <feature.icon className="size-[1.125rem]" />
              </span>
              <h3 className="mt-4 font-heading text-[0.9375rem] font-semibold">
                {feature.title}
              </h3>
              <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                {feature.description}
              </p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
