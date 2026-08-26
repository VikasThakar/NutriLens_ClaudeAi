"use client";

import { useTheme } from "next-themes";
import { Monitor, Moon, Sun } from "lucide-react";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

const OPTIONS = [
  { value: "light", label: "Light", icon: Sun },
  { value: "dark", label: "Dark", icon: Moon },
  { value: "system", label: "System", icon: Monitor },
] as const;

/**
 * next-themes returns `undefined` for `theme` during SSR and the first client
 * render, then updates — so no local "mounted" flag is needed to stay
 * hydration-safe.
 */
export function ThemeToggle({ className }: { className?: string }) {
  const { theme, setTheme } = useTheme();

  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        render={
          <Button
            variant="ghost"
            size="icon-sm"
            aria-label="Change theme"
            className={cn("text-muted-foreground hover:text-foreground", className)}
          />
        }
      >
        {/* Both icons render and cross-fade so the button never shifts. */}
        <Sun className="size-4 scale-100 rotate-0 transition-transform duration-300 dark:scale-0 dark:-rotate-90" />
        <Moon className="absolute size-4 scale-0 rotate-90 transition-transform duration-300 dark:scale-100 dark:rotate-0" />
      </DropdownMenuTrigger>

      <DropdownMenuContent align="end" className="w-36 min-w-36">
        {OPTIONS.map((option) => (
          <DropdownMenuItem
            key={option.value}
            onClick={() => setTheme(option.value)}
            className={cn("h-9 gap-2 px-2", theme === option.value && "text-primary")}
          >
            <option.icon className="size-4" />
            {option.label}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

/** Inline segmented control — used on the Settings screen. */
export function ThemeSegmentedControl() {
  const { theme, setTheme } = useTheme();

  return (
    <div
      role="radiogroup"
      aria-label="Theme"
      className="inline-flex w-full max-w-sm gap-1 rounded-xl bg-muted p-1"
    >
      {OPTIONS.map((option) => {
        const active = theme === option.value;

        return (
          <button
            key={option.value}
            type="button"
            role="radio"
            aria-checked={active}
            onClick={() => setTheme(option.value)}
            className={cn(
              "flex h-9 flex-1 items-center justify-center gap-1.5 rounded-lg text-sm font-medium transition-colors",
              "focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
              active
                ? "bg-card text-foreground shadow-sm ring-1 ring-foreground/10"
                : "text-muted-foreground hover:text-foreground",
            )}
          >
            <option.icon className="size-4" />
            {option.label}
          </button>
        );
      })}
    </div>
  );
}
