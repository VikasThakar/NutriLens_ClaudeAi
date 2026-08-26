import type { Metadata } from "next";

import { InsightsScreen } from "@/components/insights/insights-screen";

export const metadata: Metadata = {
  title: "Insights",
  description: "Weekly AI summaries of your own logged nutrition.",
};

export default function InsightsPage() {
  return <InsightsScreen />;
}
