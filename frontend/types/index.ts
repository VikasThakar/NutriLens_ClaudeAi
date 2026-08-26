export * from "./api";

import type { LucideIcon } from "lucide-react";

export interface NavItem {
  label: string;
  href: string;
  icon: LucideIcon;
  /** Not yet built — shown but flagged as coming in a later phase. */
  soon?: boolean;
}

export type MacroKey = "calories" | "protein" | "carbs" | "fat";