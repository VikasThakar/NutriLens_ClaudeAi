"use client";

import Link from "next/link";
import { Camera, Eye, MoreVertical, Pencil, Trash2, UtensilsCrossed } from "lucide-react";

import { cn } from "@/lib/utils";
import {
  MACRO_META,
  formatCalories,
  formatMacro,
  formatMealTime,
  mealTypeMeta,
} from "@/lib/nutrition";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { ConfidenceBadge } from "@/components/meals/confidence-badge";
import type { Meal } from "@/types/api";

/**
 * One meal in a list: thumbnail, name, time, macros, calories, and the
 * view / edit / delete actions.
 *
 * Shared by the Today dashboard's meal-type groups and the History day view, so
 * a meal looks and behaves identically wherever it is listed.
 */
export function MealRow({
  meal,
  onView,
  onDelete,
  deleting = false,
  /** History lists mix meal types, so the type is worth showing there. */
  showMealType = false,
}: {
  meal: Meal;
  onView: () => void;
  onDelete: () => void;
  deleting?: boolean;
  showMealType?: boolean;
}) {
  const typeMeta = mealTypeMeta(meal.meal_type);
  const itemCount = meal.items?.length ?? meal.item_count;

  return (
    <li
      className={cn(
        "group flex items-center gap-3 rounded-2xl bg-card p-2.5 ring-1 ring-foreground/10 transition-opacity",
        deleting && "opacity-50",
      )}
    >
      {/* Thumbnail */}
      <button
        type="button"
        onClick={onView}
        aria-label={`View ${meal.meal_name}`}
        className="relative size-16 shrink-0 overflow-hidden rounded-xl bg-muted focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
      >
        {meal.image_url ? (
          // Signed, expiring URL from the API — not a next/image source.
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={meal.image_url}
            alt=""
            className="absolute inset-0 size-full object-cover transition-transform duration-300 group-hover:scale-105"
          />
        ) : (
          <span className="absolute inset-0 flex items-center justify-center text-muted-foreground">
            <UtensilsCrossed className="size-5" />
          </span>
        )}
      </button>

      {/* Body */}
      <button
        type="button"
        onClick={onView}
        className="min-w-0 flex-1 text-left focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
      >
        <p className="truncate text-sm font-semibold">{meal.meal_name}</p>

        <p className="mt-0.5 flex items-center gap-1.5 text-[0.75rem] text-muted-foreground">
          {showMealType && (
            <span className="flex items-center gap-1">
              <typeMeta.icon className="size-3" />
              {typeMeta.label}
            </span>
          )}
          {formatMealTime(meal.consumed_at) && (
            <>
              {showMealType && <span aria-hidden="true">·</span>}
              <span className="tabular-nums">{formatMealTime(meal.consumed_at)}</span>
            </>
          )}
          {meal.source === "ai_photo" && (
            <>
              <span aria-hidden="true">·</span>
              <Camera className="size-3" />
            </>
          )}
          {typeof itemCount === "number" && (
            <>
              <span aria-hidden="true">·</span>
              <span>
                {itemCount} item{itemCount === 1 ? "" : "s"}
              </span>
            </>
          )}
        </p>

        <p className="mt-1.5 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-[0.6875rem] text-muted-foreground tabular-nums">
          {(["protein", "carbs", "fat"] as const).map((macro) => (
            <span key={macro} className="flex items-center gap-1">
              <span
                aria-hidden="true"
                className="size-1.5 rounded-full"
                style={{ backgroundColor: MACRO_META[macro].cssVar }}
              />
              {formatMacro(meal.totals[macro])}
            </span>
          ))}
        </p>
      </button>

      {/* Calories + actions */}
      <div className="flex shrink-0 flex-col items-end gap-1">
        <span className="text-sm font-semibold tabular-nums">
          {formatCalories(meal.totals.calories)}
          <span className="ml-0.5 text-[0.6875rem] font-medium text-muted-foreground">
            kcal
          </span>
        </span>

        {meal.source === "ai_photo" && meal.ai_confidence !== null && (
          <ConfidenceBadge value={meal.ai_confidence} />
        )}

        <DropdownMenu>
          <DropdownMenuTrigger
            render={
              <Button
                variant="ghost"
                size="icon-sm"
                aria-label={`Actions for ${meal.meal_name}`}
                className="text-muted-foreground"
                disabled={deleting}
              />
            }
          >
            <MoreVertical className="size-4" />
          </DropdownMenuTrigger>

          <DropdownMenuContent align="end" className="w-44 min-w-44">
            <DropdownMenuItem className="h-9 gap-2 px-2" onClick={onView}>
              <Eye className="size-4" />
              View details
            </DropdownMenuItem>
            <DropdownMenuItem
              className="h-9 gap-2 px-2"
              render={<Link href={`/meals/${meal.id}/edit`} />}
            >
              <Pencil className="size-4" />
              Edit meal
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              variant="destructive"
              className="h-9 gap-2 px-2"
              onClick={onDelete}
            >
              <Trash2 className="size-4" />
              Delete
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </li>
  );
}
