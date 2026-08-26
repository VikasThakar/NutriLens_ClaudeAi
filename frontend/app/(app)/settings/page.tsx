import type { Metadata } from "next";

import { PageHeader } from "@/components/shared/page-header";
import { SettingsPanels } from "@/components/settings/settings-panels";

export const metadata: Metadata = {
  title: "Settings",
  description: "Manage your profile, appearance and session.",
};

export default function SettingsPage() {
  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Account"
        title="Settings"
        description="Your profile, how NutriLens looks, and your session."
      />
      <SettingsPanels />
    </div>
  );
}
