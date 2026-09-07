import type { Metadata } from "next";
import Link from "next/link";

import { LegalPage } from "@/components/marketing/legal-page";

export const metadata: Metadata = {
  title: "Consent",
  description:
    "What you agree to when you use NutriLens, and how that agreement can be withdrawn.",
};

export default function ConsentPage() {
  return (
    <LegalPage title="Consent" updated="7 September 2026">
      <section>
        <h2>Purpose</h2>
        <p>
          NutriLens estimates the calories and macronutrients in a meal from a
          photograph you upload, and tracks those estimates against the goals
          you set. Creating an account and logging a meal is how you consent to
          that processing. Estimates are approximations, not medical advice.
        </p>
      </section>

      <section>
        <h2>Data Usage</h2>
        <p>
          We process the account details you provide, the meal photos you
          upload, the analysis derived from them, and the goals you set. Photos
          are sent to our AI analysis provider to produce an estimate, and are
          stored so you can revisit an analysis later. Everything is scoped to
          your account. We do not sell your data, and we do not share it with
          advertisers. See our{" "}
          <Link href="/privacy" className="text-foreground hover:underline">
            Privacy
          </Link>{" "}
          page for what we store and how long.
        </p>
      </section>

      <section>
        <h2>Voluntary Agreement</h2>
        <p>
          Using NutriLens is voluntary. You choose which meals to photograph and
          which goals to set, and you can stop at any time. You can withdraw
          your consent by deleting a meal, or by deleting your account — which
          removes your meals, images, goals and insights. Withdrawing consent
          does not undo analyses that already ran.
        </p>
      </section>

      <section>
        <h2>Contact</h2>
        <p>
          Questions about this page, or about a specific consent request, can be
          sent to privacy@nutrilens.app.
        </p>
      </section>
    </LegalPage>
  );
}
