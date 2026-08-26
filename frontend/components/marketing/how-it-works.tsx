import { Camera, ScanSearch, TrendingUp } from "lucide-react";

const STEPS = [
  {
    step: "01",
    title: "Capture",
    icon: Camera,
    description: "Take a photo or upload your meal.",
    detail:
      "Straight from your camera or your gallery. One plate, one photo — no searching a database for every ingredient.",
  },
  {
    step: "02",
    title: "Analyze",
    icon: ScanSearch,
    description:
      "AI identifies foods, estimates portions, and calculates nutrition.",
    detail:
      "Every item on the plate is detected separately, with a confidence score and a portion estimate you can correct.",
  },
  {
    step: "03",
    title: "Track",
    icon: TrendingUp,
    description: "Monitor your daily intake and long-term progress.",
    detail:
      "Daily macro targets, a full history of your meals, and weekly AI insights that tell you what actually changed.",
  },
];

export function HowItWorks() {
  return (
    <section
      id="how-it-works"
      className="scroll-mt-20 border-t border-border py-20 lg:py-28"
    >
      <div className="mx-auto w-full max-w-6xl px-5 lg:px-8">
        <div className="max-w-2xl">
          <p className="text-sm font-semibold tracking-wide text-primary uppercase">
            How it works
          </p>
          <h2 className="mt-3 font-heading text-3xl font-semibold sm:text-4xl">
            Three steps from plate to progress
          </h2>
          <p className="mt-4 text-base leading-relaxed text-muted-foreground">
            No weighing scales, no ingredient-by-ingredient searching. Photograph
            the meal and correct what the model got wrong.
          </p>
        </div>

        <ol className="mt-12 grid gap-5 md:grid-cols-3 lg:gap-6">
          {STEPS.map((item) => (
            <li
              key={item.step}
              className="group relative flex flex-col rounded-2xl bg-card p-6 ring-1 ring-foreground/10 transition-shadow duration-300 elevate hover:elevate-lg"
            >
              <div className="flex items-center justify-between">
                <span className="flex size-11 items-center justify-center rounded-xl bg-primary/12 text-primary">
                  <item.icon className="size-5" />
                </span>
                <span className="font-mono text-xs font-medium text-muted-foreground/70">
                  {item.step}
                </span>
              </div>

              <h3 className="mt-5 font-heading text-lg font-semibold">
                {item.title}
              </h3>
              <p className="mt-1.5 text-sm font-medium text-foreground/85">
                {item.description}
              </p>
              <p className="mt-3 text-sm leading-relaxed text-muted-foreground">
                {item.detail}
              </p>
            </li>
          ))}
        </ol>
      </div>
    </section>
  );
}
