import type { Metadata } from "next";

import { GoalsForm } from "@/components/goals/goals-form";
import { PageHeader } from "@/components/shared/page-header";

export const metadata: Metadata = {
  title: "Goals",
  description: "Set your goal and daily macro targets.",
};

export default function GoalsPage() {
  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Nutrition"
        title="Goals & targets"
        description="Change your goal or fine-tune the daily numbers NutriLens tracks you against."
      />
      <GoalsForm />
    </div>
  );
}
