import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { EditMealScreen } from "@/components/meals/edit-meal-screen";

export const metadata: Metadata = {
  title: "Edit meal",
  description: "Adjust a logged meal's items, portions and macros.",
};

export default async function EditMealPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const mealId = Number(id);

  if (!Number.isInteger(mealId) || mealId <= 0) {
    notFound();
  }

  return <EditMealScreen mealId={mealId} />;
}
