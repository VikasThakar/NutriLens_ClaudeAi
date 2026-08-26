"use client";

import { ImagePlus, PencilLine, RotateCcw, TriangleAlert, WifiOff } from "lucide-react";

import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api-client";

/**
 * Shown when analysis fails. Every path forward stays open: retry the same
 * photo, pick a different one, or type the meal in by hand. A dead end here
 * would mean the user simply loses the meal.
 */
export function AnalysisErrorPanel({
  error,
  onRetry,
  onChoosePhoto,
  onManualEntry,
}: {
  error: ApiError;
  onRetry: () => void;
  onChoosePhoto: () => void;
  onManualEntry: () => void;
}) {
  const isNetwork = error.isNetworkError;
  const canRetry = error.retryable;

  return (
    <section className="rounded-2xl bg-card p-6 ring-1 ring-foreground/10 sm:p-8">
      <span className="flex size-12 items-center justify-center rounded-2xl bg-fat/15 text-fat">
        {isNetwork ? (
          <WifiOff className="size-5" />
        ) : (
          <TriangleAlert className="size-5" />
        )}
      </span>

      <h2 className="mt-5 font-heading text-lg font-semibold">
        {isNetwork ? "No connection to NutriLens" : "That analysis didn't work"}
      </h2>

      <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
        {error.message}
      </p>

      {error.status === 429 && (
        <p className="mt-3 rounded-lg bg-muted/70 px-3.5 py-3 text-[0.8125rem] leading-relaxed text-muted-foreground">
          You&apos;ve run several analyses in quick succession. Wait a minute
          before trying again — or add this meal manually now.
        </p>
      )}

      <div className="mt-7 flex flex-col gap-2.5 sm:flex-row">
        {canRetry && (
          <Button size="lg" onClick={onRetry} className="sm:flex-1">
            <RotateCcw />
            Try again
          </Button>
        )}
        <Button
          variant="outline"
          size="lg"
          onClick={onChoosePhoto}
          className="sm:flex-1"
        >
          <ImagePlus />
          Choose another photo
        </Button>
      </div>

      <Button
        variant="ghost"
        size="lg"
        onClick={onManualEntry}
        className="mt-2.5 w-full text-muted-foreground"
      >
        <PencilLine />
        Enter this meal manually
      </Button>
    </section>
  );
}
