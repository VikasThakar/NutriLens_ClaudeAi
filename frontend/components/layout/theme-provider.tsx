"use client";

import { ThemeProvider as NextThemesProvider } from "next-themes";
import type * as React from "react";

/**
 * Light / dark / system, persisted to localStorage by next-themes and applied
 * as a `class` on <html> before paint (no flash).
 */
export function ThemeProvider({ children }: { children: React.ReactNode }) {
  return (
    <NextThemesProvider
      attribute="class"
      defaultTheme="system"
      enableSystem
      disableTransitionOnChange
      storageKey="nutrilens-theme"
    >
      {children}
    </NextThemesProvider>
  );
}