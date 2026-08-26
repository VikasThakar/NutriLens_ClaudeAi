import type { Metadata } from "next";

import { ApiKeysScreen } from "@/components/developer/api-keys-screen";

export const metadata: Metadata = {
  title: "API keys",
  description: "Create and manage keys for the NutriLens Partner API.",
};

export default function DeveloperPage() {
  return <ApiKeysScreen />;
}
