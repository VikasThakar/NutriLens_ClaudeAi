"use client";

import * as React from "react";
import { Lock, RotateCcw, Trash2 } from "lucide-react";

import { cn } from "@/lib/utils";
import { MACRO_META } from "@/lib/nutrition";
import {
  PORTION_UNITS,
  hasBaseline,
  itemIsDirty,
  type DraftItem,
} from "@/lib/meal-draft";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import type { MacroField } from "@/types/api";

const MACRO_INPUTS: { field: MacroField; label: string; unit: string }[] = [
  { field: "calories", label: "Calories", unit: "kcal" },
  { field: "protein", label: "Protein", unit: "g" },
  { field: "carbs", label: "Carbs", unit: "g" },
  { field: "fat", label: "Fat", unit: "g" },
];

interface FoodItemCardProps {
  item: DraftItem;
  index: number;
  errors: Record<string, string>;
  onNameChange: (value: string) => void;
  onPortionChange: (value: string) => void;
  onUnitChange: (value: string) => void;
  onMacroChange: (field: MacroField, value: string) => void;
  onReset: () => void;
  onRemove: () => void;
  canRemove: boolean;
}

export function FoodItemCard({
  item,
  index,
  errors,
  onNameChange,
  onPortionChange,
  onUnitChange,
  onMacroChange,
  onReset,
  onRemove,
  canRemove,
}: FoodItemCardProps) {
  const nameError = errors[`${item.key}.name`];
  const portionError = errors[`${item.key}.portion_amount`];
  const scalable = hasBaseline(item);

  return (
    <li className="rounded-2xl bg-card p-4 ring-1 ring-foreground/10">
      {/* Header: name + confidence + delete */}
      <div className="flex items-start gap-2">
        <div className="min-w-0 flex-1 space-y-2">
          <Label htmlFor={`${item.key}-name`} className="sr-only">
            Food name
          </Label>
          <Input
            id={`${item.key}-name`}
            value={item.name}
            placeholder={`Food item ${index + 1}`}
            aria-invalid={Boolean(nameError)}
            onChange={(event) => onNameChange(event.target.value)}
            className="h-10 font-medium"
          />
          {nameError && (
            <p className="text-[0.75rem] text-destructive">{nameError}</p>
          )}
        </div>

        <Button
          variant="ghost"
          size="icon-sm"
          aria-label={`Remove ${item.name || `food item ${index + 1}`}`}
          onClick={onRemove}
          disabled={!canRemove}
          className="mt-0.5 shrink-0 text-muted-foreground hover:text-destructive"
        >
          <Trash2 className="size-4" />
        </Button>
      </div>

      {/* Meta row — only once the user has touched this item. */}
      {itemIsDirty(item) && (
        <div className="mt-2.5 flex flex-wrap items-center gap-2">
          <span className="text-[0.6875rem] font-medium text-muted-foreground">
            Edited
          </span>
          {scalable && (
            <Button
              variant="ghost"
              size="xs"
              onClick={onReset}
              className="ml-auto text-muted-foreground"
            >
              <RotateCcw />
              Reset to AI estimate
            </Button>
          )}
        </div>
      )}

      {/* Portion */}
      <div className="mt-4 grid grid-cols-[1fr_7.5rem] gap-2">
        <div className="space-y-1.5">
          <Label htmlFor={`${item.key}-portion`} className="text-[0.75rem]">
            Portion
          </Label>
          <Input
            id={`${item.key}-portion`}
            type="number"
            inputMode="decimal"
            min={0}
            step="any"
            value={item.portion_amount}
            aria-invalid={Boolean(portionError)}
            onChange={(event) => onPortionChange(event.target.value)}
            className="h-10 tabular-nums"
          />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor={`${item.key}-unit`} className="text-[0.75rem]">
            Unit
          </Label>
          <Select
            value={item.portion_unit}
            onValueChange={(value) => onUnitChange(String(value))}
          >
            <SelectTrigger id={`${item.key}-unit`} className="h-10 w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {/* A unit the AI returned that is outside our list still shows. */}
              {(PORTION_UNITS as readonly string[]).includes(item.portion_unit)
                ? null
                : (
                    <SelectItem value={item.portion_unit}>
                      {item.portion_unit}
                    </SelectItem>
                  )}
              {PORTION_UNITS.map((unit) => (
                <SelectItem key={unit} value={unit}>
                  {unit}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>

      {portionError && (
        <p className="mt-1.5 text-[0.75rem] text-destructive">{portionError}</p>
      )}

      {scalable && (
        <p className="mt-2 text-[0.75rem] leading-relaxed text-muted-foreground">
          Change the portion and the macros below rescale automatically. Type over
          a value to keep it fixed.
        </p>
      )}

      {/* Macros */}
      <div className="mt-4 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
        {MACRO_INPUTS.map(({ field, label, unit }) => {
          const locked = item.locked_macros.includes(field);
          const meta = MACRO_META[field];

          return (
            <div key={field} className="space-y-1.5">
              <Label
                htmlFor={`${item.key}-${field}`}
                className="justify-between text-[0.75rem]"
              >
                <span className="flex items-center gap-1.5">
                  <span
                    aria-hidden="true"
                    className="size-2 rounded-full"
                    style={{ backgroundColor: meta.cssVar }}
                  />
                  {label}
                </span>
                {locked && (
                  <span
                    title="You set this value — portion changes will not overwrite it."
                    className="flex items-center text-muted-foreground"
                  >
                    <Lock className="size-3" />
                  </span>
                )}
              </Label>

              <div className="relative">
                <Input
                  id={`${item.key}-${field}`}
                  type="number"
                  inputMode="decimal"
                  min={0}
                  step="any"
                  value={item[field]}
                  onChange={(event) => onMacroChange(field, event.target.value)}
                  className={cn(
                    "h-10 pr-9 tabular-nums",
                    locked && "border-foreground/25",
                  )}
                />
                <span className="pointer-events-none absolute inset-y-0 right-2.5 flex items-center text-[0.6875rem] font-medium text-muted-foreground">
                  {unit}
                </span>
              </div>
            </div>
          );
        })}
      </div>
    </li>
  );
}
