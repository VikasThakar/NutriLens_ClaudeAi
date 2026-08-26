"use client";

import * as React from "react";

/**
 * Matches a CSS media query. Built on useSyncExternalStore so the value is read
 * during render rather than written from an effect — no cascading re-render, and
 * SSR gets a stable `false`.
 */
export function useMediaQuery(query: string): boolean {
  const subscribe = React.useCallback(
    (onStoreChange: () => void) => {
      const list = window.matchMedia(query);
      list.addEventListener("change", onStoreChange);
      return () => list.removeEventListener("change", onStoreChange);
    },
    [query],
  );

  const getSnapshot = React.useCallback(
    () => window.matchMedia(query).matches,
    [query],
  );

  return React.useSyncExternalStore(subscribe, getSnapshot, () => false);
}

export function useIsDesktop(): boolean {
  return useMediaQuery("(min-width: 1024px)");
}
