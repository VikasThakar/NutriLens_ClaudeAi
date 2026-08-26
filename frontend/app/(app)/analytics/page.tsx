import type { Metadata } from "next";

import { AnalyticsScreen } from "@/components/analytics/analytics-screen";

export const metadata: Metadata = {
  title: "Analytics",
  description: "Calories and macros over time, built from your logged meals.",
};

export default function AnalyticsPage() {
  return <AnalyticsScreen />;
}
