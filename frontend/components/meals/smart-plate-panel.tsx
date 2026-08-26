"use client";

import * as React from "react";
import Link from "next/link";
import {
  AlertCircle,
  ChevronDown,
  Loader2,
  RefreshCw,
  Sparkles,
  Target,
  Undo2,
  UtensilsCrossed,
} from "lucide-react";

import { cn } from "@/lib/utils";
import { ApiError } from "@/lib/api-client";
import { MACRO_META, formatCalories, formatMacro } from "@/lib/nutrition";
import {
  SMART_PLATE_DEBOUNCE_MS,
  SMART_PLATE_RATING_META,
  SMART_PLATE_ROWS,
  SMART_PLATE_STATUS_META,
  applySmartPlateChanges,
  draftToSmartPlateInput,
  signedCalories,
  signedMacro,
  smartPlateSignature,
} from "@/lib/smart-plate";
import { mealsService } from "@/services";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import type { MealDraft } from "@/lib/meal-draft";
import type {
  MacroField,
  SmartPlateAnalysis,
  SmartPlateOptimization,
} from "@/types/api";

interface SmartPlatePanelProps {
  draft: MealDraft;
  onDraftChange: (draft: MealDraft) => void;
  /** Set when editing a meal that is already saved, so it is not double-counted. */
  mealId?: number | null;
}

interface UndoEntry {
  draft: MealDraft;
  title: string;
}

/**
 * NutriLens Smart Plate — "Understand your meal. Optimize your day."
 *
 * A layer on the existing review screen, not a replacement for it: the meal
 * above stays editable throughout, saving is never blocked, and every change
 * this panel makes goes through the same draft functions the inputs use.
 *
 * It re-analyses whenever the plate actually changes, so the score on screen
 * always describes the meal on screen.
 */
export function SmartPlatePanel({
  draft,
  onDraftChange,
  mealId = null,
}: SmartPlatePanelProps) {
  const [analysis, setAnalysis] = React.useState<SmartPlateAnalysis | null>(null);
  const [loading, setLoading] = React.useState(true);
  const [refreshing, setRefreshing] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);
  const [expanded, setExpanded] = React.useState<string | null>(null);
  const [undoStack, setUndoStack] = React.useState<UndoEntry[]>([]);
  const [notice, setNotice] = React.useState<string | null>(null);
  const [retryKey, setRetryKey] = React.useState(0);

  /**
   * True from the moment a suggestion is applied until the next analysis lands.
   *
   * `refreshing` is not enough on its own: the re-analysis is debounced, so for
   * a few hundred milliseconds after an Apply the cards on screen still
   * describe the plate as it was. Tapping the same card twice in that window
   * would apply the same change again — and for "add 95 g of prawns" that means
   * 190 g of prawns.
   */
  const [suggestionsStale, setSuggestionsStale] = React.useState(false);

  const signature = React.useMemo(
    () => smartPlateSignature(draft, mealId),
    [draft, mealId],
  );

  /** The draft this panel produced last, to tell our own edits from the user's. */
  const ownDraftRef = React.useRef<MealDraft | null>(null);
  const hasLoadedRef = React.useRef(false);

  /* ---------------------------------------------------------------- */
  /* Analysis                                                          */
  /* ---------------------------------------------------------------- */

  React.useEffect(() => {
    let cancelled = false;

    const run = async () => {
      if (hasLoadedRef.current) setRefreshing(true);

      try {
        const { data } = await mealsService.smartPlate(
          draftToSmartPlateInput(draft, mealId),
        );

        if (cancelled) return;

        setAnalysis(data);
        setError(null);
        setSuggestionsStale(false);
      } catch (caught) {
        if (cancelled) return;

        setError(
          caught instanceof ApiError
            ? caught.message
            : "Smart Plate could not analyse this meal.",
        );
      } finally {
        if (!cancelled) {
          hasLoadedRef.current = true;
          setLoading(false);
          setRefreshing(false);
        }
      }
    };

    // The first analysis runs immediately; later ones wait for typing to settle.
    const delay = hasLoadedRef.current ? SMART_PLATE_DEBOUNCE_MS : 0;
    const timer = window.setTimeout(() => void run(), delay);

    return () => {
      cancelled = true;
      window.clearTimeout(timer);
    };
    // `signature` is the whole dependency: it changes exactly when the plate
    // does. `draft` itself is a new object on every keystroke anywhere.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [signature, retryKey]);

  /**
   * A hand edit after an Apply retires the undo history: "Undo" must never
   * restore a state that would silently discard the user's own change.
   */
  React.useEffect(() => {
    if (undoStack.length === 0) return;
    if (ownDraftRef.current === draft) return;

    setUndoStack([]);
    setNotice(null);
  }, [draft, undoStack.length]);

  const retry = () => {
    setLoading(true);
    setError(null);
    hasLoadedRef.current = false;
    setRetryKey((key) => key + 1);
  };

  /* ---------------------------------------------------------------- */
  /* Apply / undo                                                      */
  /* ---------------------------------------------------------------- */

  const apply = (optimization: SmartPlateOptimization) => {
    if (suggestionsStale) return;

    const result = applySmartPlateChanges(draft, optimization.changes);

    if (!result.ok) {
      // The plate moved under the suggestion. Say so and re-analyse rather
      // than applying something that no longer means what it said.
      setNotice(result.reason ?? null);
      setSuggestionsStale(true);
      setRetryKey((key) => key + 1);
      return;
    }

    setUndoStack((stack) => [...stack, { draft, title: optimization.title }]);
    setNotice(null);
    setExpanded(null);
    setSuggestionsStale(true);
    ownDraftRef.current = result.draft;
    onDraftChange(result.draft);
  };

  const undo = () => {
    const previous = undoStack[undoStack.length - 1];

    if (!previous) return;

    setUndoStack((stack) => stack.slice(0, -1));
    setNotice(null);
    setSuggestionsStale(true);
    ownDraftRef.current = previous.draft;
    onDraftChange(previous.draft);
  };

  /* ---------------------------------------------------------------- */
  /* Render                                                            */
  /* ---------------------------------------------------------------- */

  const lastUndo = undoStack[undoStack.length - 1];

  return (
    <section
      aria-labelledby="smart-plate-heading"
      className="overflow-hidden rounded-2xl bg-card ring-1 ring-foreground/10"
    >
      <header className="relative flex items-start justify-between gap-3 px-4 pt-4 sm:px-5 sm:pt-5">
        <div
          aria-hidden="true"
          className="brand-glow pointer-events-none absolute inset-0 opacity-50"
        />

        <div className="relative min-w-0">
          <h2
            id="smart-plate-heading"
            className="flex items-center gap-1.5 text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase"
          >
            <Sparkles className="size-3.5 text-primary" />
            Smart Plate
          </h2>
          <p className="mt-1 text-[0.8125rem] leading-relaxed text-muted-foreground">
            Understand your meal. Optimize your day.
          </p>
        </div>

        {refreshing && (
          <span
            role="status"
            className="relative flex shrink-0 items-center gap-1.5 text-[0.6875rem] text-muted-foreground"
          >
            <Loader2 className="size-3 animate-spin" />
            Updating
          </span>
        )}
      </header>

      <div className="p-4 sm:p-5">
        {loading && <PanelSkeleton />}

        {!loading && error && <PanelError message={error} onRetry={retry} />}

        {!loading && !error && analysis?.status === "no_goals" && (
          <NoGoals message={analysis.message} meal={analysis.meal} />
        )}

        {!loading && !error && analysis?.status === "empty_meal" && (
          <EmptyPlate message={analysis.message} />
        )}

        {!loading && !error && analysis?.status === "ok" && (
          <div className="space-y-5">
            <ScoreBlock analysis={analysis} />

            <Breakdown analysis={analysis} />

            <div className="border-t border-border pt-4">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase">
                  Optimize your meal
                </h3>

                {lastUndo && (
                  <Button
                    variant="ghost"
                    size="xs"
                    onClick={undo}
                    disabled={suggestionsStale}
                    className="text-muted-foreground"
                  >
                    <Undo2 />
                    Undo {lastUndo.title}
                  </Button>
                )}
              </div>

              {notice && (
                <p className="mt-2.5 rounded-lg bg-muted px-3 py-2 text-[0.75rem] leading-relaxed text-muted-foreground">
                  {notice}
                </p>
              )}

              <div
                className={cn(
                  "mt-3 space-y-2.5 transition-opacity",
                  // Until the next analysis lands, these cards describe the
                  // plate as it was a moment ago, so they are visibly held back
                  // rather than left tappable.
                  (refreshing || suggestionsStale) &&
                    "pointer-events-none opacity-50",
                )}
                aria-busy={refreshing || suggestionsStale}
              >
                {analysis.optimizations.map((optimization) => (
                  <OptimizationCard
                    key={optimization.id}
                    optimization={optimization}
                    expanded={expanded === optimization.id}
                    onToggle={() =>
                      setExpanded((current) =>
                        current === optimization.id ? null : optimization.id,
                      )
                    }
                    onApply={() => apply(optimization)}
                  />
                ))}
              </div>
            </div>
          </div>
        )}
      </div>
    </section>
  );
}

/* -------------------------------------------------------------------------- */

function ScoreBlock({ analysis }: { analysis: SmartPlateAnalysis }) {
  const score = analysis.meal_fit_score ?? 0;
  const rating = analysis.rating ?? "good_fit";
  const meta = SMART_PLATE_RATING_META[rating];

  return (
    <div>
      <div className="flex items-end gap-3">
        <p className="font-heading text-4xl leading-none font-semibold tabular-nums">
          <span className={meta.className}>{score.toFixed(1)}</span>
          <span className="ml-1 text-base font-medium text-muted-foreground">
            / 10
          </span>
        </p>
        <p className={cn("pb-0.5 text-sm font-semibold", meta.className)}>
          {analysis.rating_label}
        </p>
      </div>

      {/* A plain 0–10 track. The number is the message; this is just its shape. */}
      <div
        className="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-muted"
        role="progressbar"
        aria-label="Meal fit score out of 10"
        aria-valuenow={score}
        aria-valuemin={0}
        aria-valuemax={10}
      >
        <div
          className={cn(
            "h-full rounded-full transition-[width] duration-700 ease-out",
            meta.trackClassName,
          )}
          style={{ width: `${Math.max(0, Math.min(100, score * 10))}%` }}
        />
      </div>

      {analysis.summary && (
        <p className="mt-3 text-[0.8125rem] leading-relaxed text-muted-foreground">
          {analysis.summary}
        </p>
      )}
    </div>
  );
}

function Breakdown({ analysis }: { analysis: SmartPlateAnalysis }) {
  const breakdown = analysis.breakdown;

  if (!breakdown) return null;

  return (
    <div>
      <h3 className="text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase">
        How this fits your day
      </h3>

      <ul className="mt-2.5 grid grid-cols-2 gap-2 sm:grid-cols-4">
        {SMART_PLATE_ROWS.map((macro) => {
          const row = breakdown[macro];
          const meta = SMART_PLATE_STATUS_META[row.status];

          return (
            <li
              key={macro}
              className="rounded-xl bg-muted/50 px-3 py-2.5 ring-1 ring-foreground/5"
            >
              <p className="flex items-center gap-1.5 text-[0.625rem] font-semibold tracking-wide text-muted-foreground uppercase">
                <span
                  aria-hidden="true"
                  className="size-1.5 shrink-0 rounded-full"
                  style={{ backgroundColor: MACRO_META[macro].cssVar }}
                />
                <span className="truncate">{MACRO_META[macro].short}</span>
              </p>

              <p
                className={cn(
                  "mt-1.5 flex items-center gap-1.5 text-[0.8125rem] font-semibold",
                  meta.className,
                )}
              >
                <meta.icon aria-hidden="true" className="size-3.5 shrink-0" />
                <span className="truncate">{row.label}</span>
              </p>
            </li>
          );
        })}
      </ul>

      {/* Only the rows that need attention explain themselves. The rest are
          already saying "fine" with two words, and repeating it in a sentence
          would bury the ones that matter. */}
      <ul className="mt-2.5 space-y-1.5">
        {SMART_PLATE_ROWS.filter(
          (macro) => SMART_PLATE_STATUS_META[breakdown[macro].status].needsAttention,
        ).map((macro) => {
          const row = breakdown[macro];
          const meta = SMART_PLATE_STATUS_META[row.status];

          return (
            <li
              key={macro}
              className="flex items-start gap-2 text-[0.75rem] leading-relaxed text-muted-foreground"
            >
              <meta.icon
                aria-hidden="true"
                className={cn("mt-0.5 size-3.5 shrink-0", meta.className)}
              />
              <span>
                <span className="font-medium text-foreground">
                  {MACRO_META[macro].label}:
                </span>{" "}
                {row.message}
              </span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

function OptimizationCard({
  optimization,
  expanded,
  onToggle,
  onApply,
}: {
  optimization: SmartPlateOptimization;
  expanded: boolean;
  onToggle: () => void;
  onApply: () => void;
}) {
  const bodyId = `smart-plate-${optimization.id}`;

  if (!optimization.applicable) {
    return (
      <div className="rounded-xl bg-muted/40 px-3.5 py-3 ring-1 ring-foreground/5">
        <p className="flex items-center gap-2 text-[0.8125rem] font-semibold text-muted-foreground">
          <span aria-hidden="true" className="opacity-60">
            {optimization.emoji}
          </span>
          {optimization.title}
        </p>
        <p className="mt-1 text-[0.75rem] leading-relaxed text-muted-foreground">
          {optimization.unavailable_reason}
        </p>
      </div>
    );
  }

  return (
    <div className="rounded-xl bg-background/60 ring-1 ring-foreground/10">
      <div className="p-3.5">
        <div className="flex items-start justify-between gap-3">
          <p className="flex min-w-0 items-center gap-2 text-[0.875rem] font-semibold">
            <span aria-hidden="true">{optimization.emoji}</span>
            <span className="truncate">{optimization.title}</span>
          </p>

          <ScoreDelta
            from={optimization.current_score}
            to={optimization.new_score}
          />
        </div>

        <p className="mt-1.5 text-[0.8125rem] leading-relaxed text-muted-foreground">
          {optimization.description}
        </p>

        <div className="mt-3 grid grid-cols-2 gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={onToggle}
            aria-expanded={expanded}
            aria-controls={bodyId}
          >
            {expanded ? "Hide" : "Preview"}
            <ChevronDown
              className={cn("transition-transform", expanded && "rotate-180")}
            />
          </Button>
          <Button size="sm" onClick={onApply}>
            Apply
          </Button>
        </div>
      </div>

      {expanded && (
        <div
          id={bodyId}
          className="space-y-3 border-t border-border px-3.5 py-3"
        >
          {optimization.detail && (
            <p className="text-[0.8125rem] leading-relaxed text-muted-foreground">
              {optimization.detail}
            </p>
          )}

          {optimization.macro_difference && (
            <MacroDifference difference={optimization.macro_difference} />
          )}

          {optimization.projected_meal && (
            <p className="text-[0.75rem] text-muted-foreground tabular-nums">
              New meal total:{" "}
              <span className="font-medium text-foreground">
                {formatCalories(optimization.projected_meal.calories)} kcal
              </span>
              {" · "}
              {formatMacro(optimization.projected_meal.protein)} protein
              {" · "}
              {formatMacro(optimization.projected_meal.carbs)} carbs
              {" · "}
              {formatMacro(optimization.projected_meal.fat)} fat
            </p>
          )}

          {optimization.notes.map((note) => (
            <p
              key={note}
              className="flex items-start gap-2 rounded-lg bg-carbs/10 px-3 py-2 text-[0.75rem] leading-relaxed text-carbs"
            >
              <AlertCircle className="mt-px size-3.5 shrink-0" />
              <span>{note}</span>
            </p>
          ))}
        </div>
      )}
    </div>
  );
}

function ScoreDelta({ from, to }: { from: number | null; to: number | null }) {
  if (from === null || to === null) return null;

  return (
    <p className="flex shrink-0 items-center gap-1 text-[0.75rem] font-medium tabular-nums">
      <span className="text-muted-foreground">{from.toFixed(1)}</span>
      <span aria-hidden="true" className="text-muted-foreground">
        →
      </span>
      <span className="text-primary">{to.toFixed(1)}</span>
    </p>
  );
}

function MacroDifference({
  difference,
}: {
  difference: Record<MacroField, number>;
}) {
  return (
    <ul className="grid grid-cols-4 gap-2">
      {(["calories", "protein", "carbs", "fat"] as MacroField[]).map((macro) => {
        const value = difference[macro];
        const zero = Math.round(value * 10) / 10 === 0;

        return (
          <li
            key={macro}
            className="rounded-lg bg-muted/60 px-2 py-1.5 text-center"
          >
            <p className="text-[0.625rem] font-medium tracking-wide text-muted-foreground uppercase">
              {MACRO_META[macro].short}
            </p>
            {/* Direction is carried by the sign, not by colour: less fat and
                more protein are both improvements, and colouring one red would
                say otherwise. */}
            <p
              className={cn(
                "mt-0.5 text-[0.8125rem] font-semibold tabular-nums",
                zero ? "text-muted-foreground" : "text-foreground",
              )}
            >
              {macro === "calories"
                ? signedCalories(value)
                : signedMacro(value)}
            </p>
          </li>
        );
      })}
    </ul>
  );
}

/* -------------------------------------------------------------------------- */

function NoGoals({
  message,
  meal,
}: {
  message: string | null;
  meal: SmartPlateAnalysis["meal"];
}) {
  return (
    <div className="space-y-3.5">
      <div className="flex items-start gap-3">
        <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/12 text-primary">
          <Target className="size-[1.125rem]" />
        </span>
        <div className="min-w-0">
          <p className="text-sm font-semibold">
            {message ??
              "Set your nutrition goals to unlock personalized meal optimization."}
          </p>
          <p className="mt-1 text-[0.8125rem] leading-relaxed text-muted-foreground">
            Smart Plate compares a meal against what is left of your day, so it
            needs daily targets to measure against. This meal comes to{" "}
            <span className="font-medium text-foreground tabular-nums">
              {formatCalories(meal.calories)} kcal
            </span>{" "}
            with {formatMacro(meal.protein)} protein — you can still save it now
            and set targets later.
          </p>
        </div>
      </div>

      <Button render={<Link href="/goals" />} variant="outline" size="sm">
        Set nutrition goals
      </Button>
    </div>
  );
}

function EmptyPlate({ message }: { message: string | null }) {
  return (
    <div className="flex items-start gap-3">
      <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted text-muted-foreground">
        <UtensilsCrossed className="size-[1.125rem]" />
      </span>
      <p className="text-[0.8125rem] leading-relaxed text-muted-foreground">
        {message ??
          "Add a food item with some nutrition in it and Smart Plate can tell you how the meal fits your day."}
      </p>
    </div>
  );
}

function PanelError({
  message,
  onRetry,
}: {
  message: string;
  onRetry: () => void;
}) {
  return (
    <div
      role="alert"
      className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
    >
      <div className="flex items-start gap-3">
        <AlertCircle className="mt-0.5 size-5 shrink-0 text-destructive" />
        <div>
          <p className="text-sm font-semibold">Smart Plate is unavailable</p>
          <p className="mt-1 text-[0.8125rem] leading-relaxed text-muted-foreground">
            {message} You can still edit and save this meal as usual.
          </p>
        </div>
      </div>
      <Button variant="outline" size="sm" onClick={onRetry} className="shrink-0">
        <RefreshCw />
        Try again
      </Button>
    </div>
  );
}

function PanelSkeleton() {
  return (
    <div className="space-y-4">
      <Skeleton className="h-10 w-32" />
      <Skeleton className="h-1.5 w-full rounded-full" />
      <Skeleton className="h-8 w-full" />
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <Skeleton className="h-14 rounded-xl" />
        <Skeleton className="h-14 rounded-xl" />
        <Skeleton className="h-14 rounded-xl" />
        <Skeleton className="h-14 rounded-xl" />
      </div>
      <Skeleton className="h-24 rounded-xl" />
    </div>
  );
}
