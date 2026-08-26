"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import { AlertCircle } from "lucide-react";
import { toast } from "sonner";

import { ApiError } from "@/lib/api-client";
import {
  draftFromMeal,
  draftToUpdateInput,
  validateDraft,
  type MealDraft,
} from "@/lib/meal-draft";
import { mealsService } from "@/services";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { MealEditor } from "@/components/meals/meal-editor";

export function EditMealScreen({ mealId }: { mealId: number }) {
  const router = useRouter();

  const [draft, setDraft] = React.useState<MealDraft | null>(null);
  const [loadError, setLoadError] = React.useState<string | null>(null);
  const [loading, setLoading] = React.useState(true);
  const [saving, setSaving] = React.useState(false);
  const [errors, setErrors] = React.useState<Record<string, string>>({});
  const [formError, setFormError] = React.useState<string | null>(null);

  React.useEffect(() => {
    let cancelled = false;

    void (async () => {
      try {
        const { data } = await mealsService.get(mealId);
        if (cancelled) return;
        setDraft(draftFromMeal(data));
      } catch (caught) {
        if (cancelled) return;
        setLoadError(
          caught instanceof ApiError ? caught.message : "Could not load this meal.",
        );
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [mealId]);

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
      await mealsService.update(mealId, draftToUpdateInput(draft));
      toast.success("Meal updated.");
      router.push("/today");
    } catch (caught) {
      if (caught instanceof ApiError) {
        setFormError(caught.message);

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

  if (loading) {
    return (
      <div className="space-y-5">
        <Skeleton className="h-16 rounded-xl" />
        <Skeleton className="h-64 rounded-2xl" />
        <Skeleton className="h-40 rounded-2xl" />
      </div>
    );
  }

  if (loadError || !draft) {
    return (
      <div className="space-y-6">
        <PageHeader eyebrow="Edit meal" title="Meal not available" />
        <div
          role="alert"
          className="flex flex-col gap-4 rounded-2xl bg-card p-6 ring-1 ring-destructive/25 sm:flex-row sm:items-center sm:justify-between"
        >
          <div className="flex items-start gap-3">
            <AlertCircle className="mt-0.5 size-5 shrink-0 text-destructive" />
            <p className="text-sm text-muted-foreground">
              {loadError ?? "This meal could not be found."}
            </p>
          </div>
          <Button variant="outline" onClick={() => router.push("/today")}>
            Back to Today
          </Button>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Edit meal"
        title={draft.meal_name || "Edit meal"}
        description="Adjust items, portions or macros. Totals update as you type."
      />
      <MealEditor
        draft={draft}
        onChange={setDraft}
        onSubmit={() => void save()}
        onCancel={() => router.push("/today")}
        errors={errors}
        formError={formError}
        saving={saving}
        submitLabel="Save changes"
        title="Meal details"
      />
    </div>
  );
}
