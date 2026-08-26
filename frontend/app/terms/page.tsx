import type { Metadata } from "next";

import { LegalPage } from "@/components/marketing/legal-page";

export const metadata: Metadata = {
  title: "Terms",
  description: "The terms that apply to your use of NutriLens.",
};

export default function TermsPage() {
  return (
    <LegalPage title="Terms of Service" updated="25 August 2026">
      <section>
        <h2>Not medical advice</h2>
        <p>
          NutriLens produces estimates. Calorie and macronutrient figures derived
          from a photograph are approximations and must not be relied on for
          medical, clinical or dietary treatment decisions. Speak to a qualified
          professional before making significant changes to your diet.
        </p>
      </section>

      <section>
        <h2>Your account</h2>
        <p>
          You are responsible for keeping your credentials secure and for the
          activity that happens under your account.
        </p>
      </section>

      <section>
        <h2>Acceptable use</h2>
        <p>
          Do not attempt to disrupt the service, access other users&apos; data, or
          reverse-engineer the analysis pipeline.
        </p>
      </section>

      <section>
        <h2>Changes</h2>
        <p>
          These terms may change as the product develops. Material changes will
          be announced in the app.
        </p>
      </section>
    </LegalPage>
  );
}
