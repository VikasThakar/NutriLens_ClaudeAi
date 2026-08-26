import type { Metadata } from "next";

import { CoachScreen } from "@/components/coach/coach-screen";

export const metadata: Metadata = {
  title: "AI Coach",
  description:
    "Ask questions about your meals, goals and nutrition progress, answered from your own logged data.",
};

export default function CoachPage() {
  return <CoachScreen />;
}
