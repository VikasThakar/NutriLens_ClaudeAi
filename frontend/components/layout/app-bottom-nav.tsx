"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { Plus } from "lucide-react";

import { cn } from "@/lib/utils";
import {
  ADD_MEAL_ITEM,
  BOTTOM_NAV_LEFT,
  BOTTOM_NAV_RIGHT,
  type AppNavItem,
} from "@/lib/navigation";

function NavTab({ item, active }: { item: AppNavItem; active: boolean }) {
  return (
    <Link
      href={item.href}
      aria-current={active ? "page" : undefined}
      className={cn(
        "flex h-full min-w-0 flex-1 flex-col items-center justify-center gap-1 rounded-lg text-[0.625rem] font-medium transition-colors",
        "focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
        active ? "text-primary" : "text-muted-foreground active:text-foreground",
      )}
    >
      <item.icon className="size-5 shrink-0" strokeWidth={active ? 2.4 : 2} />
      <span className="w-full truncate text-center">{item.label}</span>
    </Link>
  );
}

/**
 * Mobile primary navigation. Add Meal is raised in the centre — the action the
 * whole product exists for — with the five screens people return to either
 * side of it.
 *
 * Five tabs is one more than the layout originally carried, so the centre slot
 * and the gaps are tightened to match: every label still clears its own width
 * at 360px, and `truncate` keeps the narrowest phones from overflowing rather
 * than letting a label push the row sideways.
 */
export function AppBottomNav() {
  const pathname = usePathname();

  return (
    <nav
      aria-label="Primary"
      className="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-background/92 backdrop-blur-lg lg:hidden"
    >
      <div className="mx-auto flex h-16 max-w-lg items-stretch gap-0.5 px-1.5 pb-safe sm:gap-1 sm:px-2">
        {BOTTOM_NAV_LEFT.map((item) => (
          <NavTab key={item.href} item={item} active={pathname === item.href} />
        ))}

        {/* Raised primary action */}
        <div className="flex w-16 shrink-0 items-start justify-center sm:w-[4.5rem]">
          <Link
            href={ADD_MEAL_ITEM.href}
            aria-label="Add a meal"
            aria-current={pathname === ADD_MEAL_ITEM.href ? "page" : undefined}
            className={cn(
              "-mt-5 flex size-14 flex-col items-center justify-center rounded-2xl bg-primary text-primary-foreground",
              "ring-4 ring-background transition-transform duration-200 active:scale-95",
              "focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
              "shadow-[0_8px_24px_-8px_color-mix(in_oklch,var(--primary)_65%,transparent)]",
            )}
          >
            <Plus className="size-6" strokeWidth={2.5} />
            <span className="text-[0.5625rem] font-semibold">Add</span>
          </Link>
        </div>

        {BOTTOM_NAV_RIGHT.map((item) => (
          <NavTab key={item.href} item={item} active={pathname === item.href} />
        ))}
      </div>
    </nav>
  );
}
