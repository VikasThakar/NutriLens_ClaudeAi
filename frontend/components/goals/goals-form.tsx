"use client";

import * as React from "react";
import {
  Calculator,
  Check,
  Dumbbell,
  Leaf,
  Loader2,
  RotateCcw,
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
import { Skeleton } from "@/components/ui/skeleton";
import { FieldError, FormError } from "@/components/shared/form-message";
import { GoalCalculator } from "@/components/goals/goal-calculator";
import { GoalHistory } from "@/components/goals/goal-history";
import type {
  GoalEstimate,
  GoalSource,
  GoalType,
  NutritionGoal,
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

type Targets = Record<(typeof TARGET_FIELDS)[number]["key"], string>;

/** kcal per gram — used to show how the macro split adds up. */
const ENERGY_PER_GRAM = { protein: 4, carbs: 4, fat: 9 } as const;

export function GoalsForm() {
  const { user, refresh } = useAuth();

  const [loading, setLoading] = React.useState(true);
  const [goal, setGoal] = React.useState<GoalType>("improve_nutrition");
  const [targets, setTargets] = React.useState<Targets>({
    calorie_target: "",
    protein_target: "",
    carb_target: "",
    fat_target: "",
  });
  const [fieldErrors, setFieldErrors] = React.useState<Partial<Targets>>({});
  const [formError, setFormError] = React.useState<string | null>(null);
  const [saving, setSaving] = React.useState(false);
  const [calculatorOpen, setCalculatorOpen] = React.useState(false);
  /**
   * Provenance for the next save. Seeded from the loaded goal, and set to
   * `calculator` once the calculator has filled the form — the numbers may be
   * adjusted afterwards, but they still came from it.
   */
  const [source, setSource] = React.useState<GoalSource>("manual");
  const [maintenance, setMaintenance] = React.useState<number | null>(null);
  const [historyKey, setHistoryKey] = React.useState(0);

  const applyGoal = React.useCallback((current: NutritionGoal) => {
    setGoal(current.goal_type);
    setTargets({
      calorie_target: String(current.calorie_target),
      protein_target: String(current.protein_target),
      carb_target: String(current.carb_target),
      fat_target: String(current.fat_target),
    });
    setSource(current.source ?? "manual");
    setMaintenance(current.estimated_maintenance_calories ?? null);
  }, []);

  React.useEffect(() => {
    let cancelled = false;

    async function load() {
      try {
        const { data } = await goalsService.current();
        if (cancelled) return;

        if (data) {
          applyGoal(data);
        } else {
          const fallback = GOAL_OPTIONS[3];
          setGoal(fallback.value);
          setTargets({
            calorie_target: String(fallback.targets.calorie_target),
            protein_target: String(fallback.targets.protein_target),
            carb_target: String(fallback.targets.carb_target),
            fat_target: String(fallback.targets.fat_target),
          });
        }
      } catch (error) {
        if (cancelled) return;
        setFormError(
          error instanceof ApiError ? error.message : "Could not load your goals.",
        );
      } finally {
        if (!cancelled) setLoading(false);
      }
    }

    void load();

    return () => {
      cancelled = true;
    };
  }, [applyGoal]);

  const chooseGoal = (next: GoalType) => setGoal(next);

  const resetToRecommended = () => {
    const option = GOAL_OPTIONS.find((item) => item.value === goal);
    if (!option) return;

    setTargets({
      calorie_target: String(option.targets.calorie_target),
      protein_target: String(option.targets.protein_target),
      carb_target: String(option.targets.carb_target),
      fat_target: String(option.targets.fat_target),
    });
    setSource("manual");
    setMaintenance(null);
    setFieldErrors({});
  };

  /** The calculator hands its estimate here; nothing is saved until the user does. */
  const applyEstimate = (estimate: GoalEstimate) => {
    setGoal(estimate.goal_type);
    setTargets({
      calorie_target: String(estimate.targets.calorie_target),
      protein_target: String(estimate.targets.protein_target),
      carb_target: String(estimate.targets.carb_target),
      fat_target: String(estimate.targets.fat_target),
    });
    setSource("calculator");
    setMaintenance(estimate.maintenance_calories);
    setFieldErrors({});
    toast.success("Estimate applied. Adjust anything, then save.");
  };

  const editTarget = (key: keyof Targets, value: string) => {
    setTargets((current) => ({ ...current, [key]: value }));
  };

  const save = async () => {
    setFormError(null);
    setFieldErrors({});

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

    setSaving(true);

    try {
      const { data } = await goalsService.update({
        goal_type: goal,
        ...parsed.data,
        source,
        estimated_maintenance_calories: source === "calculator" ? maintenance : null,
      });
      applyGoal(data);
      await refresh();
      setHistoryKey((key) => key + 1);
      toast.success("Your goals have been updated.");
    } catch (error) {
      if (error instanceof ApiError && error.isValidation) {
        const next: Partial<Targets> = {};
        for (const field of TARGET_FIELDS) {
          const message = error.fieldError(field.key);
          if (message) next[field.key] = message;
        }
        setFieldErrors(next);
        if (!Object.keys(next).length) setFormError(error.message);
      } else {
        setFormError(
          error instanceof ApiError
            ? error.message
            : "Could not save your goals. Please try again.",
        );
      }
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="space-y-5">
        <Skeleton className="h-44 rounded-2xl" />
        <Skeleton className="h-72 rounded-2xl" />
      </div>
    );
  }

  return (
    <div className="space-y-5">
      <FormError message={formError} />

      {/* Goal */}
      <section className="rounded-2xl bg-card p-5 ring-1 ring-foreground/10 sm:p-6">
        <h2 className="font-heading text-[0.9375rem] font-semibold">
          What are you working toward?
        </h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Changing your goal does not overwrite your targets — use “Use
          recommended” below if you want them recalculated.
        </p>

        <div
          role="radiogroup"
          aria-label="Your goal"
          className="mt-5 grid gap-3 sm:grid-cols-2"
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
                  "flex items-center gap-3 rounded-xl bg-background p-3.5 text-left ring-1 transition-all duration-200",
                  "focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
                  selected
                    ? "ring-2 ring-primary"
                    : "ring-border hover:ring-foreground/25",
                )}
              >
                <span
                  className={cn(
                    "flex size-9 shrink-0 items-center justify-center rounded-lg transition-colors",
                    selected
                      ? "bg-primary text-primary-foreground"
                      : "bg-muted text-muted-foreground",
                  )}
                >
                  <Icon className="size-4" />
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block text-sm font-semibold">
                    {option.label}
                  </span>
                  <span className="block truncate text-xs text-muted-foreground">
                    {option.description}
                  </span>
                </span>
                {selected && (
                  <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground">
                    <Check className="size-3" strokeWidth={3} />
                  </span>
                )}
              </button>
            );
          })}
        </div>
      </section>

      {/* Calculator */}
      <section className="relative overflow-hidden rounded-2xl bg-card p-5 ring-1 ring-foreground/10 sm:p-6">
        <div
          aria-hidden="true"
          className="brand-glow pointer-events-none absolute inset-0 opacity-50"
        />
        <div className="relative flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex items-start gap-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/12 text-primary">
              <Calculator className="size-[1.125rem]" />
            </span>
            <div>
              <h2 className="font-heading text-[0.9375rem] font-semibold">
                Not sure what your numbers should be?
              </h2>
              <p className="mt-1 max-w-lg text-sm leading-relaxed text-muted-foreground">
                The calculator estimates a starting point from your age, height,
                weight and activity level. It fills the form below rather than
                saving anything, so you stay in control of every figure.
              </p>
            </div>
          </div>
          <Button
            variant="outline"
            size="lg"
            className="shrink-0"
            onClick={() => setCalculatorOpen(true)}
          >
            <Sparkles />
            Open calculator
          </Button>
        </div>
      </section>

      {/* Targets */}
      <section className="rounded-2xl bg-card p-5 ring-1 ring-foreground/10 sm:p-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 className="font-heading text-[0.9375rem] font-semibold">
              Daily targets
            </h2>
            <p className="mt-1 text-sm text-muted-foreground">
              These drive the rings and bars on your Today dashboard, and the
              “days close to target” figure in Analytics.
            </p>
          </div>
          <Button variant="ghost" size="sm" onClick={resetToRecommended}>
            <RotateCcw />
            Use recommended
          </Button>
        </div>

        {source === "calculator" && maintenance !== null && (
          <p className="mt-4 rounded-lg bg-primary/8 px-3.5 py-3 text-[0.8125rem] leading-relaxed text-muted-foreground ring-1 ring-primary/15">
            Estimated from the calculator, against a maintenance level of about{" "}
            <span className="font-semibold text-foreground tabular-nums">
              {formatCalories(maintenance)} kcal
            </span>
            . These are estimates — edit anything below before saving.
          </p>
        )}

        <div className="mt-5 grid gap-4 sm:grid-cols-2">
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
                  onChange={(event) => editTarget(field.key, event.target.value)}
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

        <MacroBudget targets={targets} />

        <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-end">
          <Button size="lg" onClick={() => void save()} disabled={saving}>
            {saving ? (
              <>
                <Loader2 className="animate-spin" />
                Saving…
              </>
            ) : (
              "Save goals"
            )}
          </Button>
        </div>
      </section>

      <GoalHistory reloadKey={historyKey} />

      {user?.nutrition_goal?.updated_at && (
        <p className="text-xs text-muted-foreground">
          Last updated{" "}
          {new Date(user.nutrition_goal.updated_at).toLocaleString("en-US", {
            dateStyle: "medium",
            timeStyle: "short",
          })}
          .
        </p>
      )}

      <GoalCalculator
        open={calculatorOpen}
        goalType={goal}
        onClose={() => setCalculatorOpen(false)}
        onApply={applyEstimate}
      />
    </div>
  );
}

/**
 * Shows how the three macro targets add up in kcal against the calorie target,
 * so obviously inconsistent numbers are visible before saving.
 */
function MacroBudget({ targets }: { targets: Targets }) {
  const protein = Number(targets.protein_target) || 0;
  const carbs = Number(targets.carb_target) || 0;
  const fat = Number(targets.fat_target) || 0;
  const calorieTarget = Number(targets.calorie_target) || 0;

  const fromMacros =
    protein * ENERGY_PER_GRAM.protein +
    carbs * ENERGY_PER_GRAM.carbs +
    fat * ENERGY_PER_GRAM.fat;

  if (!calorieTarget || !fromMacros) return null;

  const difference = fromMacros - calorieTarget;
  const drift = Math.abs(difference) / calorieTarget;
  const aligned = drift <= 0.08;

  return (
    <div className="mt-5 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-lg bg-muted/60 px-3.5 py-3 text-[0.8125rem]">
      <span className="text-muted-foreground">
        Your macro split works out to{" "}
        <span className="font-semibold text-foreground tabular-nums">
          {formatCalories(fromMacros)} kcal
        </span>
      </span>
      <span
        className={cn(
          "font-medium tabular-nums",
          aligned ? "text-primary" : "text-muted-foreground",
        )}
      >
        {aligned
          ? "Matches your calorie target"
          : `${difference > 0 ? "+" : "−"}${formatCalories(Math.abs(difference))} kcal vs target`}
      </span>
    </div>
  );
}
