"use client";

import * as React from "react";

const subscribe = () => () => {};
const getSnapshot = () => true;
const getServerSnapshot = () => false;

/**
 * False during prerender and the first client render, true afterwards.
 *
 * Lets a component derive client-only values — local time, `matchMedia` — during
 * render rather than writing them from an effect, which keeps hydration stable
 * without a cascading re-render.
 */
export function useHydrated(): boolean {
  return React.useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
}
