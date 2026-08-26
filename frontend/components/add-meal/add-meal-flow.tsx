"use client";

import * as React from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { toast } from "sonner";

import { ApiError } from "@/lib/api-client";
import { suggestedMealType } from "@/lib/nutrition";
import {
  draftForManualEntry,
  draftFromAnalysis,
  draftToStoreInput,
  validateDraft,
  type MealDraft,
} from "@/lib/meal-draft";
import { mealsService } from "@/services";
import { useHydrated } from "@/hooks/use-hydrated";
import { PageHeader } from "@/components/shared/page-header";
import { MealEditor } from "@/components/meals/meal-editor";
import {
  MealPhotoPicker,
  type PhotoSelection,
} from "@/components/add-meal/meal-photo-picker";
import { AnalysisProgress } from "@/components/add-meal/analysis-progress";
import { AnalysisErrorPanel } from "@/components/add-meal/analysis-error-panel";
import type { MealImageRef, MealType } from "@/types/api";

type Step = "capture" | "analyzing" | "failed" | "review";

/** After this long, the loading screen admits things are slow. */
const SLOW_AFTER_MS = 9000;

export function AddMealFlow() {
  const router = useRouter();
  const searchParams = useSearchParams();

  const hydrated = useHydrated();

  /**
   * `?mode=manual` opens straight into manual entry, which is what the
   * dashboard's Quick Add links to. Without it the flow starts on capture as
   * before.
   */
  const wantsManualEntry = searchParams.get("mode") === "manual";

  const [step, setStep] = React.useState<Step>("capture");
  const [selection, setSelection] = React.useState<PhotoSelection | null>(null);
  const [draft, setDraft] = React.useState<MealDraft | null>(null);
  const [analysisError, setAnalysisError] = React.useState<ApiError | null>(null);
  const [slow, setSlow] = React.useState(false);
  const [saving, setSaving] = React.useState(false);
  const [errors, setErrors] = React.useState<Record<string, string>>({});
  const [formError, setFormError] = React.useState<string | null>(null);

  /**
   * The image row Laravel created for this upload. Kept even when analysis
   * fails, so a manually entered meal can still keep the photo.
   */
  const [image, setImage] = React.useState<MealImageRef | null>(null);

  /**
   * The meal type defaults to whatever fits the current time of day, until the
   * user picks one. Derived rather than stored so the time-of-day guess can be
   * computed on the client — the prerendered HTML has no idea what o'clock it is
   * where the user is.
   */
  const [chosenMealType, setChosenMealType] = React.useState<MealType | null>(null);
  const mealType: MealType =
    chosenMealType ?? (hydrated ? suggestedMealType(new Date()) : "lunch");

  // Release the object URL when the chosen photo changes or the flow unmounts.
  React.useEffect(() => {
    const url = selection?.previewUrl;
    return () => {
      if (url) URL.revokeObjectURL(url);
    };
  }, [selection?.previewUrl]);

  /**
   * `?mode=manual` opens the editor directly.
   *
   * Applied during render rather than from an effect, which avoids a cascading
   * re-render, and gated on `hydrated` so the blank draft is stamped with the
   * real local time of day rather than the prerender fallback. `manualApplied`
   * makes it strictly once — otherwise "take a photo instead" could never get
   * back to the capture screen while the parameter is still in the URL.
   */
  const [manualApplied, setManualApplied] = React.useState(false);

  if (wantsManualEntry && hydrated && !manualApplied) {
    setManualApplied(true);
    setDraft(draftForManualEntry(mealType, null));
    setStep("review");
  }

  const analyze = React.useCallback(
    async (photo: PhotoSelection, type: MealType) => {
      setStep("analyzing");
      setAnalysisError(null);
      setSlow(false);

      const slowTimer = window.setTimeout(() => setSlow(true), SLOW_AFTER_MS);

      try {
        const { data } = await mealsService.analyze(photo.file);

        setImage(data.meal_image);
        setDraft(draftFromAnalysis(data.analysis, type, data.meal_image));
        setErrors({});
        setFormError(null);
        setStep("review");
      } catch (caught) {
        const apiError =
          caught instanceof ApiError
            ? caught
            : new ApiError(0, "Something went wrong while analysing the photo.");

        // The endpoint returns the stored image even on failure.
        const failedImage = (apiError.payload.data as { meal_image?: MealImageRef })
          ?.meal_image;
        if (failedImage) setImage(failedImage);

        setAnalysisError(apiError);
        setStep("failed");
      } finally {
        window.clearTimeout(slowTimer);
        setSlow(false);
      }
    },
    [],
  );

  const startManualEntry = () => {
    setDraft(draftForManualEntry(mealType, image));
    setErrors({});
    setFormError(null);
    setStep("review");
  };

  const backToCapture = () => {
    setStep("capture");
    setAnalysisError(null);
    setDraft(null);
  };

  const save = async () => {
    if (!draft) return;

    const validation = validateDraft(draft);
    setErrors(validation.errors);
    setFormError(null);

    if (!validation.ok) {
      setFormError("Please fix the highlighted fields before saving.");
      return;
    }

    setSaving(true);

    try {
      const { tip } = await mealsService.create(draftToStoreInput(draft));

      // The NutriLens Tip is computed server-side from the day's remaining
      // targets, so it costs nothing to show it the moment the meal lands.
      toast.success(tip ? `✨ ${tip.headline}` : "Meal saved.", {
        description: tip?.body,
        duration: tip ? 6000 : undefined,
      });

      router.push("/today");
    } catch (caught) {
      if (caught instanceof ApiError) {
        setFormError(caught.message);

        // Map Laravel's dotted item paths back onto the draft's item keys.
        if (caught.isValidation) {
          const mapped: Record<string, string> = {};

          for (const [field, messages] of Object.entries(caught.errors)) {
            const match = /^items\.(\d+)\.(\w+)$/.exec(field);
            if (match) {
              const item = draft.items[Number(match[1])];
              if (item) mapped[`${item.key}.${match[2]}`] = messages[0];
            } else {
              mapped[field] = messages[0];
            }
          }

          setErrors(mapped);
        }
      } else {
        setFormError("Could not save this meal. Please try again.");
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-6">
      {step === "capture" && (
        <>
          <PageHeader
            eyebrow="Add a meal"
            title="Snap it, and we'll do the maths"
            description="Take a photo or upload one, and NutriLens will estimate the calories and macros for every item on the plate."
          />
          <MealPhotoPicker
            selection={selection}
            onSelect={setSelection}
            onClear={() => setSelection(null)}
            mealType={mealType}
            onMealTypeChange={setChosenMealType}
            onAnalyze={() => selection && void analyze(selection, mealType)}
            onManualEntry={startManualEntry}
          />
        </>
      )}

      {step === "analyzing" && (
        <>
          <PageHeader eyebrow="Add a meal" title="Reading your plate" />
          <AnalysisProgress slow={slow} />
        </>
      )}

      {step === "failed" && analysisError && (
        <>
          <PageHeader eyebrow="Add a meal" title="Analysis didn't work" />
          <AnalysisErrorPanel
            error={analysisError}
            onRetry={() => selection && void analyze(selection, mealType)}
            onChoosePhoto={() => {
              setSelection(null);
              backToCapture();
            }}
            onManualEntry={startManualEntry}
          />
        </>
      )}

      {step === "review" && draft && (
        <>
          <PageHeader
            eyebrow="Add a meal"
            title={draft.ai_provider ? "Review your meal" : "Enter your meal"}
            description={
              draft.ai_provider
                ? "Check each item and correct anything that looks off. Changing a portion rescales its macros."
                : "Add each food with its portion and macros."
            }
          />
          <MealEditor
            draft={draft}
            onChange={setDraft}
            onSubmit={() => void save()}
            onCancel={backToCapture}
            errors={errors}
            formError={formError}
            saving={saving}
            submitLabel="Save Meal"
            title={draft.ai_provider ? "AI analysis" : "Meal details"}
            description={
              draft.ai_provider
                ? "Everything here is editable before you save."
                : undefined
            }
          />
        </>
      )}
    </div>
  );
}
