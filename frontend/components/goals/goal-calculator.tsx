"use client";

import * as React from "react";
import { Calculator, Info, Loader2, ShieldQuestion } from "lucide-react";

import { cn } from "@/lib/utils";
import { ApiError } from "@/lib/api-client";
import { MACRO_META, formatCalories } from "@/lib/nutrition";
import { goalsService } from "@/services";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Separator } from "@/components/ui/separator";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetTitle,
} from "@/components/ui/sheet";
import { FieldError, FormError } from "@/components/shared/form-message";
import { StatTile } from "@/components/shared/stat-tile";
import type {
  ActivityLevel,
  BiologicalSex,
  GoalCalculatorOptions,
  GoalEstimate,
  GoalType,
} from "@/types/api";

type FieldKey = "age" | "height_cm" | "weight_kg";

interface CalculatorState {
  age: string;
  height_cm: string;
  weight_kg: string;
  activity_level: ActivityLevel | "";
  biological_sex: BiologicalSex;
}

const EMPTY: CalculatorState = {
  age: "",
  height_cm: "",
  weight_kg: "",
  activity_level: "",
  biological_sex: "unspecified",
};

/**
 * The optional goal calculator.
 *
 * Two things it does deliberately:
 *
 *  - It **never saves anything by itself.** It hands an estimate back to the
 *    Goals form, where every field is still editable, and the user saves.
 *  - It **explains why it asks for biological sex** rather than either
 *    demanding it or quietly assuming one. The Mifflin-St Jeor equation has a
 *    different constant for male and female bodies; without it the calculator
 *    uses the midpoint and says so.
 */
export function GoalCalculator({
  open,
  goalType,
  onClose,
  onApply,
}: {
  open: boolean;
  goalType: GoalType;
  onClose: () => void;
  onApply: (estimate: GoalEstimate) => void;
}) {
  const [options, setOptions] = React.useState<GoalCalculatorOptions | null>(null);
  const [values, setValues] = React.useState<CalculatorState>(EMPTY);
  const [estimate, setEstimate] = React.useState<GoalEstimate | null>(null);
  const [fieldErrors, setFieldErrors] = React.useState<Partial<Record<string, string>>>({});
  const [formError, setFormError] = React.useState<string | null>(null);
  // Starts true: the sheet is closed on the first render, and the flag is only
  // ever cleared once the one-time fetch has finished.
  const [loadingOptions, setLoadingOptions] = React.useState(true);
  const [calculating, setCalculating] = React.useState(false);
  const [showSexRationale, setShowSexRationale] = React.useState(false);

  // Options (and any previously entered metrics) are fetched the first time the
  // sheet is opened, not on every mount of the Goals page.
  React.useEffect(() => {
    if (!open || options) return;

    let cancelled = false;

    void (async () => {
      try {
        const { data } = await goalsService.calculatorOptions();
        if (cancelled) return;

        setOptions(data);
        setValues({
          age: data.saved_inputs.age?.toString() ?? "",
          height_cm: data.saved_inputs.height_cm?.toString() ?? "",
          weight_kg: data.saved_inputs.weight_kg?.toString() ?? "",
          activity_level: data.saved_inputs.activity_level ?? "",
          biological_sex: data.saved_inputs.biological_sex ?? "unspecified",
        });
      } catch (caught) {
        if (cancelled) return;
        setFormError(
          caught instanceof ApiError
            ? caught.message
            : "Could not load the calculator.",
        );
      } finally {
        if (!cancelled) setLoadingOptions(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [open, options]);

  const set = (key: keyof CalculatorState, value: string) => {
    setValues((current) => ({ ...current, [key]: value }));
    setEstimate(null);
  };

  const calculate = async () => {
    setFormError(null);
    setFieldErrors({});

    const errors: Partial<Record<string, string>> = {};

    if (!values.age.trim()) errors.age = "Enter your age.";
    if (!values.height_cm.trim()) errors.height_cm = "Enter your height.";
    if (!values.weight_kg.trim()) errors.weight_kg = "Enter your weight.";
    if (!values.activity_level) errors.activity_level = "Choose an activity level.";

    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors);
      return;
    }

    setCalculating(true);

    try {
      const { data } = await goalsService.calculate({
        age: Number(values.age),
        height_cm: Number(values.height_cm),
        weight_kg: Number(values.weight_kg),
        activity_level: values.activity_level as ActivityLevel,
        goal_type: goalType,
        biological_sex: values.biological_sex,
      });

      setEstimate(data);
    } catch (caught) {
      if (caught instanceof ApiError && caught.isValidation) {
        const next: Partial<Record<string, string>> = {};
        for (const key of ["age", "height_cm", "weight_kg", "activity_level"]) {
          const message = caught.fieldError(key);
          if (message) next[key] = message;
        }
        setFieldErrors(next);
        if (Object.keys(next).length === 0) setFormError(caught.message);
      } else {
        setFormError(
          caught instanceof ApiError
            ? caught.message
            : "Could not calculate an estimate.",
        );
      }
    } finally {
      setCalculating(false);
    }
  };

  return (
    <Sheet open={open} onOpenChange={(next) => !next && onClose()}>
      <SheetContent
        side="bottom"
        className="max-h-[92dvh] gap-0 overflow-y-auto rounded-t-2xl p-0 sm:mx-auto sm:max-w-xl"
      >
        <div className="space-y-5 p-5 pb-safe sm:p-6">
          <header>
            <span className="flex size-10 items-center justify-center rounded-xl bg-primary/12 text-primary">
              <Calculator className="size-[1.125rem]" />
            </span>
            <SheetTitle className="mt-3.5 text-lg">
              Estimate your daily targets
            </SheetTitle>
            <SheetDescription className="mt-1.5 leading-relaxed">
              A rough starting point from your body metrics and how active you
              are. Every number it produces is an estimate you can change before
              saving.
            </SheetDescription>
          </header>

          <FormError message={formError} />

          {loadingOptions && !options && (
            <p className="flex items-center gap-2 py-6 text-sm text-muted-foreground">
              <Loader2 className="size-4 animate-spin" />
              Loading the calculator…
            </p>
          )}

          {options && (
            <>
              <div className="grid gap-4 sm:grid-cols-3">
                <NumberField
                  id="calc-age"
                  label="Age"
                  unit="years"
                  value={values.age}
                  error={fieldErrors.age}
                  onChange={(value) => set("age", value)}
                />
                <NumberField
                  id="calc-height"
                  label="Height"
                  unit="cm"
                  value={values.height_cm}
                  error={fieldErrors.height_cm}
                  onChange={(value) => set("height_cm", value)}
                />
                <NumberField
                  id="calc-weight"
                  label="Weight"
                  unit="kg"
                  step="0.1"
                  value={values.weight_kg}
                  error={fieldErrors.weight_kg}
                  onChange={(value) => set("weight_kg", value)}
                />
              </div>

              <fieldset>
                <legend className="text-sm font-medium">Activity level</legend>
                <div
                  role="radiogroup"
                  aria-label="Activity level"
                  className="mt-2.5 space-y-2"
                >
                  {options.activity_levels.map((level) => {
                    const selected = values.activity_level === level.value;

                    return (
                      <button
                        key={level.value}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        onClick={() => set("activity_level", level.value)}
                        className={cn(
                          "flex w-full items-center gap-3 rounded-xl bg-background p-3 text-left ring-1 transition-all",
                          "focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
                          selected
                            ? "ring-2 ring-primary"
                            : "ring-border hover:ring-foreground/25",
                        )}
                      >
                        <span className="min-w-0 flex-1">
                          <span className="block text-sm font-semibold">
                            {level.label}
                          </span>
                          <span className="block text-xs text-muted-foreground">
                            {level.description}
                          </span>
                        </span>
                        <span className="shrink-0 text-[0.6875rem] text-muted-foreground tabular-nums">
                          ×{level.multiplier}
                        </span>
                      </button>
                    );
                  })}
                </div>
                <FieldError message={fieldErrors.activity_level} />
              </fieldset>

              {/* Biological sex — asked for, explained, and optional. */}
              <fieldset>
                <div className="flex items-center justify-between gap-3">
                  <legend className="text-sm font-medium">
                    Biological sex{" "}
                    <span className="font-normal text-muted-foreground">
                      (optional)
                    </span>
                  </legend>
                  <Button
                    variant="ghost"
                    size="sm"
                    type="button"
                    aria-expanded={showSexRationale}
                    onClick={() => setShowSexRationale((open) => !open)}
                  >
                    <ShieldQuestion />
                    Why is this asked?
                  </Button>
                </div>

                {showSexRationale && (
                  <p className="mt-2 rounded-lg bg-muted/70 p-3.5 text-[0.8125rem] leading-relaxed text-muted-foreground">
                    The calculation used here — the Mifflin-St Jeor equation — has
                    a different constant term for male and female bodies, which
                    shifts the estimate by roughly 165&nbsp;kcal. It is used for
                    that one arithmetic step and nothing else. If you would rather
                    not say, the calculator uses the midpoint of the two, which
                    makes the estimate a little rougher but still usable.
                  </p>
                )}

                <div
                  role="radiogroup"
                  aria-label="Biological sex"
                  className="mt-2.5 flex flex-wrap gap-2"
                >
                  {options.biological_sexes.map((sex) => {
                    const selected = values.biological_sex === sex.value;

                    return (
                      <button
                        key={sex.value}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        onClick={() => set("biological_sex", sex.value)}
                        className={cn(
                          "h-10 rounded-lg px-3.5 text-[0.8125rem] font-medium ring-1 transition-all",
                          "focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
                          selected
                            ? "bg-primary/10 text-primary ring-2 ring-primary"
                            : "bg-background ring-border hover:ring-foreground/25",
                        )}
                      >
                        {sex.label}
                      </button>
                    );
                  })}
                </div>
              </fieldset>

              <p className="flex items-start gap-2 text-[0.75rem] leading-relaxed text-muted-foreground">
                <Info className="mt-0.5 size-3.5 shrink-0" />
                These values are saved to your profile so you do not have to
                retype them next time. They are used only by this calculator.
              </p>

              <Button
                size="lg"
                className="w-full"
                disabled={calculating}
                onClick={() => void calculate()}
              >
                {calculating ? (
                  <>
                    <Loader2 className="animate-spin" />
                    Calculating…
                  </>
                ) : (
                  <>
                    <Calculator />
                    {estimate ? "Recalculate" : "Calculate estimate"}
                  </>
                )}
              </Button>

              {estimate && (
                <>
                  <Separator />
                  <EstimateResult
                    estimate={estimate}
                    onApply={() => {
                      onApply(estimate);
                      onClose();
                    }}
                  />
                </>
              )}
            </>
          )}
        </div>
      </SheetContent>
    </Sheet>
  );
}

function EstimateResult({
  estimate,
  onApply,
}: {
  estimate: GoalEstimate;
  onApply: () => void;
}) {
  const { targets } = estimate;

  return (
    <section>
      <h3 className="font-heading text-[0.9375rem] font-semibold">
        Your estimate
      </h3>
      <p className="mt-1 text-sm text-muted-foreground">
        For{" "}
        <span className="font-medium text-foreground">{estimate.goal_label}</span>{" "}
        at {estimate.activity_label.toLowerCase()}.
      </p>

      <div className="mt-4 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
        <StatTile
          label="Calories"
          value={formatCalories(targets.calorie_target)}
          unit="kcal"
          dotColor={MACRO_META.calories.cssVar}
        />
        <StatTile
          label="Protein"
          value={targets.protein_target}
          unit="g"
          hint={`${estimate.macro_percent.protein}% of calories`}
          dotColor={MACRO_META.protein.cssVar}
        />
        <StatTile
          label="Carbs"
          value={targets.carb_target}
          unit="g"
          hint={`${estimate.macro_percent.carbs}% of calories`}
          dotColor={MACRO_META.carbs.cssVar}
        />
        <StatTile
          label="Fat"
          value={targets.fat_target}
          unit="g"
          hint={`${estimate.macro_percent.fat}% of calories`}
          dotColor={MACRO_META.fat.cssVar}
        />
      </div>

      {/* Show the working, so the number is traceable rather than magic. */}
      <dl className="mt-4 space-y-1.5 rounded-xl bg-muted/60 p-3.5 text-[0.75rem]">
        <div className="flex justify-between gap-3">
          <dt className="text-muted-foreground">
            Resting energy ({estimate.formula})
          </dt>
          <dd className="font-medium tabular-nums">
            {formatCalories(estimate.bmr)} kcal
          </dd>
        </div>
        <div className="flex justify-between gap-3">
          <dt className="text-muted-foreground">
            × {estimate.activity_multiplier} activity = maintenance
          </dt>
          <dd className="font-medium tabular-nums">
            {formatCalories(estimate.maintenance_calories)} kcal
          </dd>
        </div>
        <div className="flex justify-between gap-3">
          <dt className="text-muted-foreground">
            Adjustment for {estimate.goal_label.toLowerCase()}
          </dt>
          <dd className="font-medium tabular-nums">
            {estimate.calorie_adjustment === 0
              ? "none"
              : `${estimate.calorie_adjustment > 0 ? "+" : "−"}${formatCalories(Math.abs(estimate.calorie_adjustment))} kcal`}
          </dd>
        </div>
        <div className="flex justify-between gap-3">
          <dt className="text-muted-foreground">Protein anchored to body weight</dt>
          <dd className="font-medium tabular-nums">
            {estimate.protein_per_kg} g/kg
          </dd>
        </div>
      </dl>

      <p className="mt-3.5 text-[0.75rem] leading-relaxed text-muted-foreground">
        <strong className="font-semibold text-foreground">
          These are estimates.
        </strong>{" "}
        They come from a population-average formula and do not account for your
        individual physiology, medical history or training.
        {!estimate.sex_was_specified &&
          " Because biological sex was not given, the midpoint of the two constants was used, which makes this rougher still."}{" "}
        Treat them as a starting point, adjust anything that looks wrong, and see
        how the next couple of weeks actually go. This is not medical or
        nutritional advice.
      </p>

      <Button size="lg" className="mt-4 w-full" onClick={onApply}>
        Use these numbers
      </Button>
      <p className="mt-2 text-center text-[0.6875rem] text-muted-foreground">
        Nothing is saved yet — the targets will fill in the form so you can adjust
        them first.
      </p>
    </section>
  );
}

function NumberField({
  id,
  label,
  unit,
  value,
  error,
  step,
  onChange,
}: {
  id: string;
  label: string;
  unit: string;
  value: string;
  error?: string;
  step?: string;
  onChange: (value: string) => void;
}) {
  return (
    <div className="space-y-2">
      <Label htmlFor={id}>{label}</Label>
      <div className="relative">
        <Input
          id={id}
          type="number"
          inputMode="decimal"
          step={step}
          min={0}
          value={value}
          aria-invalid={Boolean(error)}
          onChange={(event) => onChange(event.target.value)}
          className="pr-14 tabular-nums"
        />
        <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs font-medium text-muted-foreground">
          {unit}
        </span>
      </div>
      <FieldError message={error} />
    </div>
  );
}

/** The field keys the calculator validates client-side. */
export type CalculatorFieldKey = FieldKey;
