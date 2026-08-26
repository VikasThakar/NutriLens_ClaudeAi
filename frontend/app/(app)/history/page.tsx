import type { Metadata } from "next";

import { HistoryScreen } from "@/components/history/history-screen";

export const metadata: Metadata = {
  title: "History",
  description: "Every meal you have logged, day by day.",
};

export default function HistoryPage() {
  return <HistoryScreen />;
}
