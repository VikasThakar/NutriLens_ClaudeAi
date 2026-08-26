import type { Metadata } from "next";

import { LegalPage } from "@/components/marketing/legal-page";

export const metadata: Metadata = {
  title: "Privacy",
  description: "How NutriLens handles your account and nutrition data.",
};

export default function PrivacyPage() {
  return (
    <LegalPage title="Privacy" updated="25 August 2026">
      <section>
        <h2>What we store</h2>
        <p>
          Your name, email address, a securely hashed password, the nutrition
          goals you set, and the meals you log. Meal photos are stored so you can
          revisit an analysis later.
        </p>
      </section>

      <section>
        <h2>Your data belongs to you</h2>
        <p>
          Every meal, image and goal is scoped to your account. We do not sell
          your data, and we do not share it with advertisers.
        </p>
      </section>

      <section>
        <h2>Deleting your account</h2>
        <p>
          Deleting your account removes your meals, images, goals and insights.
        </p>
      </section>

      <section>
        <h2>Contact</h2>
        <p>
          Questions about this policy can be sent to privacy@nutrilens.app.
        </p>
      </section>
    </LegalPage>
  );
}
