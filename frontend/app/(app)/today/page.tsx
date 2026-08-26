import type { Metadata } from "next";

import { TodayDashboard } from "@/components/dashboard/today-dashboard";

export const metadata: Metadata = {
  title: "Today",
  description: "Your daily nutrition at a glance.",
};

export default function TodayPage() {
  return <TodayDashboard />;
}
