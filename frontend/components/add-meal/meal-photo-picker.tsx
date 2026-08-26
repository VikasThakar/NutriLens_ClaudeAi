"use client";

import * as React from "react";
import {
  Camera,
  ImagePlus,
  PencilLine,
  RefreshCw,
  Sparkles,
  Trash2,
} from "lucide-react";

import { cn } from "@/lib/utils";
import { MEAL_TYPES } from "@/lib/nutrition";
import { Button } from "@/components/ui/button";
import { FormError } from "@/components/shared/form-message";
import type { MealType } from "@/types/api";

/** Mirrors the Laravel validation so obvious problems are caught before upload. */
const ACCEPTED_TYPES = ["image/jpeg", "image/png", "image/webp"];
const MAX_BYTES = 12 * 1024 * 1024;

export interface PhotoSelection {
  file: File;
  previewUrl: string;
}

interface MealPhotoPickerProps {
  selection: PhotoSelection | null;
  onSelect: (selection: PhotoSelection) => void;
  onClear: () => void;
  mealType: MealType;
  onMealTypeChange: (mealType: MealType) => void;
  onAnalyze: () => void;
  onManualEntry: () => void;
  busy?: boolean;
}

export function MealPhotoPicker({
  selection,
  onSelect,
  onClear,
  mealType,
  onMealTypeChange,
  onAnalyze,
  onManualEntry,
  busy = false,
}: MealPhotoPickerProps) {
  const cameraInput = React.useRef<HTMLInputElement>(null);
  const galleryInput = React.useRef<HTMLInputElement>(null);
  const [error, setError] = React.useState<string | null>(null);

  const accept = (file: File | undefined) => {
    if (!file) return;

    if (!ACCEPTED_TYPES.includes(file.type)) {
      setError(
        "Please choose a JPEG, PNG or WebP photo. HEIC images are not supported yet — " +
          "on iPhone, set Settings › Camera › Formats to “Most Compatible”.",
      );
      return;
    }

    if (file.size > MAX_BYTES) {
      setError("That photo is larger than 12 MB. Please choose a smaller one.");
      return;
    }

    setError(null);
    onSelect({ file, previewUrl: URL.createObjectURL(file) });
  };

  const reset = (input: React.RefObject<HTMLInputElement | null>) => {
    // Clearing the value lets the user pick the same file twice in a row.
    if (input.current) input.current.value = "";
  };

  return (
    <div className="space-y-5">
      {/* capture="environment" asks mobile browsers for the rear camera. */}
      <input
        ref={cameraInput}
        type="file"
        accept="image/jpeg,image/png,image/webp"
        capture="environment"
        className="hidden"
        onChange={(event) => {
          accept(event.target.files?.[0]);
          reset(cameraInput);
        }}
      />
      <input
        ref={galleryInput}
        type="file"
        accept="image/jpeg,image/png,image/webp"
        className="hidden"
        onChange={(event) => {
          accept(event.target.files?.[0]);
          reset(galleryInput);
        }}
      />

      <FormError message={error} />

      {selection ? (
        <figure className="overflow-hidden rounded-2xl bg-card ring-1 ring-foreground/10">
          <div className="relative aspect-[4/3] w-full bg-muted">
            {/* A blob: URL from the local file — next/image cannot optimise it. */}
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src={selection.previewUrl}
              alt="The meal you are about to analyse"
              className="absolute inset-0 size-full object-cover"
            />
          </div>

          <figcaption className="flex flex-wrap items-center gap-2 border-t border-border p-3">
            <span className="mr-auto truncate text-[0.75rem] text-muted-foreground">
              {selection.file.name}
            </span>
            <Button
              variant="outline"
              size="sm"
              onClick={() => galleryInput.current?.click()}
              disabled={busy}
            >
              <RefreshCw />
              Replace
            </Button>
            <Button
              variant="ghost"
              size="sm"
              onClick={() => {
                setError(null);
                onClear();
              }}
              disabled={busy}
              className="text-muted-foreground"
            >
              <Trash2 />
              Remove
            </Button>
          </figcaption>
        </figure>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2">
          <PickerTile
            icon={Camera}
            title="Take a photo"
            body="Use your camera to shoot the plate in front of you."
            onClick={() => cameraInput.current?.click()}
            primary
          />
          <PickerTile
            icon={ImagePlus}
            title="Upload a photo"
            body="Choose an existing image from this device."
            onClick={() => galleryInput.current?.click()}
          />
        </div>
      )}

      {/* Meal type */}
      <fieldset>
        <legend className="text-[0.8125rem] font-medium">Meal type</legend>
        <div
          role="radiogroup"
          aria-label="Meal type"
          className="mt-2.5 grid grid-cols-2 gap-2 sm:grid-cols-4"
        >
          {MEAL_TYPES.map((option) => {
            const selected = mealType === option.value;

            return (
              <button
                key={option.value}
                type="button"
                role="radio"
                aria-checked={selected}
                onClick={() => onMealTypeChange(option.value)}
                className={cn(
                  "flex h-11 items-center justify-center gap-1.5 rounded-xl text-sm font-medium transition-all",
                  "focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
                  selected
                    ? "bg-primary text-primary-foreground"
                    : "bg-muted text-muted-foreground hover:text-foreground",
                )}
              >
                <option.icon className="size-4" />
                {option.label}
              </button>
            );
          })}
        </div>
      </fieldset>

      {/* Actions */}
      <div className="space-y-3">
        <Button
          size="xl"
          className="w-full"
          onClick={onAnalyze}
          disabled={!selection || busy}
        >
          <Sparkles />
          Analyze with AI
        </Button>

        <Button
          variant="ghost"
          size="sm"
          className="w-full text-muted-foreground"
          onClick={onManualEntry}
          disabled={busy}
        >
          <PencilLine />
          Enter this meal manually instead
        </Button>
      </div>
    </div>
  );
}

function PickerTile({
  icon: Icon,
  title,
  body,
  onClick,
  primary = false,
}: {
  icon: typeof Camera;
  title: string;
  body: string;
  onClick: () => void;
  primary?: boolean;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        "group flex flex-col items-start gap-2 rounded-2xl bg-card p-5 text-left ring-1 transition-all duration-200",
        "focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
        primary
          ? "ring-primary/30 hover:ring-primary/60 hover:elevate"
          : "ring-foreground/10 hover:ring-foreground/25 hover:elevate",
      )}
    >
      <span
        className={cn(
          "flex size-11 items-center justify-center rounded-xl transition-transform duration-200 group-hover:scale-105",
          primary ? "bg-primary text-primary-foreground" : "bg-muted text-muted-foreground",
        )}
      >
        <Icon className="size-5" />
      </span>
      <span className="mt-1 font-heading text-[0.9375rem] font-semibold">{title}</span>
      <span className="text-[0.8125rem] leading-relaxed text-muted-foreground">
        {body}
      </span>
    </button>
  );
}
