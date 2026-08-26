import { Suspense } from "react";
import type { Metadata } from "next";

import { AddMealFlow } from "@/components/add-meal/add-meal-flow";

export const metadata: Metadata = {
  title: "Add Meal",
  description: "Photograph a meal and let NutriLens estimate its nutrition.",
};

export default function AddMealPage() {
  return (
    // The flow reads `?mode=manual`, and useSearchParams needs a Suspense
    // boundary to stay compatible with static prerendering.
    <Suspense fallback={null}>
      <AddMealFlow />
    </Suspense>
  );
}
