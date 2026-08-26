import type { Route } from "next";
import {
  BarChart3,
  CalendarDays,
  CameraIcon,
  Code2,
  LayoutDashboard,
  Settings,
  Sparkles,
  Target,
  type LucideIcon,
} from "lucide-react";

export interface AppNavItem {
  label: string;
  href: Route;
  icon: LucideIcon;
  /** Screen exists but its feature lands in a later phase. */
  soon?: boolean;
}

/** Desktop sidebar — the full set of screens. */
export const SIDEBAR_NAV: AppNavItem[] = [
  { label: "Today", href: "/today", icon: LayoutDashboard },
  { label: "Add Meal", href: "/add-meal", icon: CameraIcon },
  { label: "History", href: "/history", icon: CalendarDays },
  { label: "Analytics", href: "/analytics", icon: BarChart3 },
  { label: "Insights", href: "/insights", icon: Sparkles },
  { label: "Goals", href: "/goals", icon: Target },
  { label: "API keys", href: "/developer", icon: Code2 },
  { label: "Settings", href: "/settings", icon: Settings },
];

/**
 * Mobile bottom bar — five slots, with Add Meal deliberately in the middle
 * where a thumb naturally lands.
 *
 * Settings is not one of them: it lives in the account menu in the top bar,
 * which frees the slot for Analytics — the screen a user actually opens
 * repeatedly.
 */
export const BOTTOM_NAV_LEFT: AppNavItem[] = [
  { label: "Today", href: "/today", icon: LayoutDashboard },
  { label: "History", href: "/history", icon: CalendarDays },
];

export const BOTTOM_NAV_RIGHT: AppNavItem[] = [
  { label: "Analytics", href: "/analytics", icon: BarChart3 },
  { label: "Insights", href: "/insights", icon: Sparkles },
];

export const ADD_MEAL_ITEM: AppNavItem = {
  label: "Add Meal",
  href: "/add-meal",
  icon: CameraIcon,
};

export const MARKETING_NAV: { label: string; href: string }[] = [
  { label: "Features", href: "#features" },
  { label: "How It Works", href: "#how-it-works" },
];
