"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import {
  ArrowLeft,
  ArrowRight,
  Calculator,
  Check,
  Dumbbell,
  Leaf,
  Loader2,
  Scale,
  Sparkles,
  TrendingDown,
} from "lucide-react";
import { toast } from "sonner";

import { cn } from "@/lib/utils";
import { ApiError } from "@/lib/api-client";
import { GOAL_OPTIONS, MACRO_META, formatCalories } from "@/lib/nutrition";
import { nutritionTargetsSchema } from "@/lib/validations";
import { goalsService } from "@/services";
import { useAuth } from "@/hooks/use-auth";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { LogoMark } from "@/components/layout/logo";
import { FieldError, FormError } from "@/components/shared/form-message";
import { GoalCalculator } from "@/components/goals/goal-calculator";
import type {
  GoalEstimate,
  GoalSource,
  GoalType,
  OnboardingInput,
} from "@/types/api";

const GOAL_ICONS: Record<GoalType, typeof Leaf> = {
  lose_weight: TrendingDown,
  maintain_weight: Scale,
  build_muscle: Dumbbell,
  improve_nutrition: Leaf,
};

const TARGET_FIELDS = [
  { key: "calorie_target", macro: "calories", label: "Calories", unit: "kcal" },
  { key: "protein_target", macro: "protein", label: "Protein", unit: "g" },
  { key: "carb_target", macro: "carbs", label: "Carbohydrates", unit: "g" },
  { key: "fat_target", macro: "fat", label: "Fat", unit: "g" },
] as const;

const STEP_LABELS = ["Welcome", "Your goal", "Daily targets"];

type Targets = Record<(typeof TARGET_FIELDS)[number]["key"], string>;

export function OnboardingFlow() {
  const router = useRouter();
  const { user, setUser } = useAuth();

  const [step, setStep] = React.useState(0);
  const [goal, setGoal] = React.useState<GoalType>("improve_nutrition");
  const [targets, setTargets] = React.useState<Targets>(() =>
    toStrings(GOAL_OPTIONS[3].targets),
  );
  const [fieldErrors, setFieldErrors] = React.useState<Partial<Targets>>({});
  const [formError, setFormError] = React.useState<string | null>(null);
  const [submitting, setSubmitting] = React.useState(false);
  const [calculatorOpen, setCalculatorOpen] = React.useState(false);
  /**
   * Where the numbers in the form came from. Seeded as `onboarding` — the
   * per-goal recommendation — and set to `calculator` once an estimate has
   * filled the fields. They stay editable afterwards, but they still came
   * from there, which is what the Goals screen reports later.
   */
  const [source, setSource] = React.useState<GoalSource>("onboarding");
  const [maintenance, setMaintenance] = React.useState<number | null>(null);

  const chooseGoal = (next: GoalType) => {
    setGoal(next);
    const option = GOAL_OPTIONS.find((item) => item.value === next);
    if (option) {
      // Pre-fill the targets step with the recommendation for this goal,
      // which also means the form is no longer showing an estimate.
      setTargets(toStrings(option.targets));
      setFieldErrors({});
      setSource("onboarding");
      setMaintenance(null);
    }
  };

  /**
   * The calculator returns an estimate and nothing else: it fills this form
   * so every figure can still be changed before onboarding is finished.
   */
  const applyEstimate = (estimate: GoalEstimate) => {
    setTargets(toStrings(estimate.targets));
    setSource("calculator");
    setMaintenance(estimate.maintenance_calories);
    setFieldErrors({});
    toast.success("Estimate applied. Adjust anything, then finish.");
  };

  /** `skip` sends only the goal, letting the API apply its defaults. */
  const submit = async (skip: boolean) => {
    setFormError(null);
    setFieldErrors({});

    let payload: OnboardingInput = { goal_type: goal };

    if (!skip) {
      const parsed = nutritionTargetsSchema.safeParse(targets);

      if (!parsed.success) {
        const next: Partial<Targets> = {};
        for (const issue of parsed.error.issues) {
          const key = issue.path[0] as keyof Targets;
          if (key && !next[key]) next[key] = issue.message;
        }
        setFieldErrors(next);
        return;
      }

      payload = {
        goal_type: goal,
        ...parsed.data,
        // Sent only when the calculator produced these numbers. Otherwise
        // the API records the goal as set during onboarding, which it was.
        ...(source === "calculator"
          ? { source, estimated_maintenance_calories: maintenance }
          : {}),
      };
    }

    setSubmitting(true);

    try {
      const { data } = await goalsService.completeOnboarding(payload);
      setUser(data);
      toast.success("You're all set. Welcome to NutriLens.");
      router.replace("/today");
    } catch (error) {
      if (error instanceof ApiError) {
        if (error.isValidation) {
          const next: Partial<Targets> = {};
          for (const field of TARGET_FIELDS) {
            const message = error.fieldError(field.key);
            if (message) next[field.key] = message;
          }
          setFieldErrors(next);
          setFormError(Object.keys(next).length ? null : error.message);
        } else {
          setFormError(error.message);
        }
      } else {
        setFormError("Something went wrong. Please try again.");
      }
      setSubmitting(false);
    }
  };

  return (
    <div className="w-full max-w-xl">
      {/* Step indicator */}
      <div className="mb-8">
        <div className="flex items-center gap-2">
          {STEP_LABELS.map((label, index) => (
            <div key={label} className="flex flex-1 flex-col gap-2">
              <span
                className={cn(
                  "h-1 rounded-full transition-colors duration-500",
                  index <= step ? "bg-primary" : "bg-muted",
                )}
              />
              <span
                className={cn(
                  "text-[0.6875rem] font-medium transition-colors",
                  index <= step ? "text-foreground" : "text-muted-foreground",
                )}
              >
                {label}
              </span>
            </div>
          ))}
        </div>
      </div>

      <FormError message={formError} className="mb-5" />

      {/* Step 1 — Welcome */}
      {step === 0 && (
        <section className="animate-rise">
          <span className="flex size-14 items-center justify-center rounded-2xl bg-primary/12">
            <LogoMark className="size-8 text-primary" />
          </span>

          <h1 className="mt-6 font-heading text-3xl font-semibold sm:text-[2.125rem]">
            Welcome to NutriLens
            {user?.name ? `, ${user.name.split(" ")[0]}` : ""}.
          </h1>
          <p className="mt-4 leading-relaxed text-muted-foreground">
            Two quick questions and your dashboard is ready. Pick the goal you
            are working toward, confirm your daily targets, and start logging
            meals from a photo.
          </p>

          <ul className="mt-8 space-y-3">
            {[
              "Choose a goal — we suggest sensible macros for it",
              "Adjust the numbers if you already know yours",
              "Change everything later from Goals",
            ].map((item) => (
              <li key={item} className="flex items-start gap-3 text-sm">
                <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-primary/12 text-primary">
                  <Check className="size-3" strokeWidth={3} />
                </span>
                <span className="text-muted-foreground">{item}</span>
              </li>
            ))}
          </ul>

          <Button
            size="xl"
            className="mt-9 w-full sm:w-auto"
            onClick={() => setStep(1)}
          >
            Get started
            <ArrowRight />
          </Button>
        </section>
      )}

      {/* Step 2 — Goal */}
      {step === 1 && (
        <section className="animate-rise">
          <h1 className="font-heading text-2xl font-semibold sm:text-3xl">
            What is your goal?
          </h1>
          <p className="mt-2 text-sm text-muted-foreground">
            This sets your starting macro split. Nothing here is permanent.
          </p>

          <div
            role="radiogroup"
            aria-label="Your goal"
            className="mt-6 grid gap-3 sm:grid-cols-2"
          >
            {GOAL_OPTIONS.map((option) => {
              const Icon = GOAL_ICONS[option.value];
              const selected = goal === option.value;

              return (
                <button
                  key={option.value}
                  type="button"
                  role="radio"
                  aria-checked={selected}
                  onClick={() => chooseGoal(option.value)}
                  className={cn(
                    "group relative flex flex-col rounded-xl bg-card p-4 text-left ring-1 transition-all duration-200",
                    "focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
                    selected
                      ? "ring-2 ring-primary elevate"
                      : "ring-foreground/10 hover:ring-foreground/20 hover:elevate",
                  )}
                >
                  <span className="flex items-center justify-between">
                    <span
                      className={cn(
                        "flex size-10 items-center justify-center rounded-lg transition-colors",
                        selected
                          ? "bg-primary text-primary-foreground"
                          : "bg-muted text-muted-foreground group-hover:text-foreground",
                      )}
                    >
                      <Icon className="size-[1.125rem]" />
                    </span>
                    {selected && (
                      <span className="flex size-5 items-center justify-center rounded-full bg-primary text-primary-foreground">
                        <Check className="size-3" strokeWidth={3} />
                      </span>
                    )}
                  </span>

                  <span className="mt-3.5 font-heading text-[0.9375rem] font-semibold">
                    {option.label}
                  </span>
                  <span className="mt-1 text-[0.8125rem] leading-relaxed text-muted-foreground">
                    {option.description}
                  </span>
                  <span className="mt-3 text-[0.6875rem] font-medium text-muted-foreground tabular-nums">
                    {option.targets.calorie_target.toLocaleString("en-US")} kcal ·{" "}
                    {option.targets.protein_target}P / {option.targets.carb_target}C /{" "}
                    {option.targets.fat_target}F
                  </span>
                </button>
              );
            })}
          </div>

          <div className="mt-8 flex flex-col gap-3 sm:flex-row">
            <Button variant="outline" size="lg" onClick={() => setStep(0)}>
              <ArrowLeft />
              Back
            </Button>
            <Button size="lg" className="sm:flex-1" onClick={() => setStep(2)}>
              Continue
              <ArrowRight />
            </Button>
          </div>
        </section>
      )}

      {/* Step 3 — Targets */}
      {step === 2 && (
        <section className="animate-rise">
          <h1 className="font-heading text-2xl font-semibold sm:text-3xl">
            Set your daily targets
          </h1>
          <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
            Pre-filled for{" "}
            <span className="font-medium text-foreground">
              {GOAL_OPTIONS.find((option) => option.value === goal)?.label}
            </span>
            . Adjust anything you already know, or skip and use our
            recommendation.
          </p>

          {/* The same estimator the Goals screen offers, on the step where
              the numbers are first asked for. */}
          <section className="relative mt-6 overflow-hidden rounded-2xl bg-card p-4 ring-1 ring-foreground/10 sm:p-5">
            <div
              aria-hidden="true"
              className="brand-glow pointer-events-none absolute inset-0 opacity-50"
            />
            <div className="relative">
              <div className="flex items-start gap-3">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/12 text-primary">
                  <Calculator className="size-[1.125rem]" />
                </span>
                <div>
                  <h2 className="font-heading text-[0.9375rem] font-semibold">
                    Not sure what your numbers should be?
                  </h2>
                  <p className="mt-1 text-[0.8125rem] leading-relaxed text-muted-foreground">
                    The calculator estimates a starting point from your age,
                    height, weight and activity level. It fills the fields
                    below rather than saving anything, so you stay in control
                    of every figure.
                  </p>
                </div>
              </div>
              <Button
                variant="outline"
                size="lg"
                className="mt-4 w-full"
                onClick={() => setCalculatorOpen(true)}
                disabled={submitting}
              >
                <Sparkles />
                Open calculator
              </Button>
            </div>
          </section>

          {source === "calculator" && maintenance !== null && (
            <p className="mt-4 rounded-lg bg-primary/8 px-3.5 py-3 text-[0.8125rem] leading-relaxed text-muted-foreground ring-1 ring-primary/15">
              Estimated from the calculator, against a maintenance level of
              about{" "}
              <span className="font-semibold text-foreground tabular-nums">
                {formatCalories(maintenance)} kcal
              </span>
              . These are estimates — edit anything below before you finish.
            </p>
          )}

          <div className="mt-6 grid gap-4 sm:grid-cols-2">
            {TARGET_FIELDS.map((field) => (
              <div key={field.key} className="space-y-2">
                <Label htmlFor={field.key} className="gap-1.5">
                  <span
                    aria-hidden="true"
                    className="size-2 rounded-full"
                    style={{ backgroundColor: MACRO_META[field.macro].cssVar }}
                  />
                  {field.label}
                </Label>
                <div className="relative">
                  <Input
                    id={field.key}
                    type="number"
                    inputMode="numeric"
                    min={0}
                    value={targets[field.key]}
                    aria-invalid={Boolean(fieldErrors[field.key])}
                    onChange={(event) =>
                      setTargets((current) => ({
                        ...current,
                        [field.key]: event.target.value,
                      }))
                    }
                    className="pr-14 tabular-nums"
                  />
                  <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs font-medium text-muted-foreground">
                    {field.unit}
                  </span>
                </div>
                <FieldError message={fieldErrors[field.key]} />
              </div>
            ))}
          </div>

          <div className="mt-6 flex items-start gap-2.5 rounded-lg bg-muted/60 px-3.5 py-3">
            <Sparkles className="mt-px size-4 shrink-0 text-primary" />
            <p className="text-[0.8125rem] leading-relaxed text-muted-foreground">
              Prefer to skip? Start with the recommendation. Once you have a
              week of meals logged, Insights will suggest adjustments.
            </p>
          </div>

          <div className="mt-8 flex flex-col gap-3 sm:flex-row">
            <Button
              variant="outline"
              size="lg"
              onClick={() => setStep(1)}
              disabled={submitting}
            >
              <ArrowLeft />
              Back
            </Button>
            <Button
              size="lg"
              className="sm:flex-1"
              onClick={() => void submit(false)}
              disabled={submitting}
            >
              {submitting ? (
                <>
                  <Loader2 className="animate-spin" />
                  Saving…
                </>
              ) : (
                <>
                  Save and finish
                  <ArrowRight />
                </>
              )}
            </Button>
          </div>

          <Button
            variant="ghost"
            size="sm"
            className="mt-3 w-full text-muted-foreground"
            onClick={() => void submit(true)}
            disabled={submitting}
          >
            Skip — use the recommended targets
          </Button>

          <GoalCalculator
            open={calculatorOpen}
            goalType={goal}
            onClose={() => setCalculatorOpen(false)}
            onApply={applyEstimate}
          />
        </section>
      )}
    </div>
  );
}

function toStrings(targets: {
  calorie_target: number;
  protein_target: number;
  carb_target: number;
  fat_target: number;
}): Targets {
  return {
    calorie_target: String(targets.calorie_target),
    protein_target: String(targets.protein_target),
    carb_target: String(targets.carb_target),
    fat_target: String(targets.fat_target),
  };
}
