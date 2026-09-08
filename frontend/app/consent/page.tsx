import type { Metadata } from "next";

import { LegalPage } from "@/components/marketing/legal-page";

export const metadata: Metadata = {
  title: "Consent",
  description:
    "What you agree to when NutriLens sends your meal data to an AI model, and how to withdraw it.",
};

export default function ConsentPage() {
  return (
    <LegalPage title="Consent" updated="8 September 2026">
      <section>
        <h2>What this page covers</h2>
        <p>
          NutriLens estimates nutrition with an AI model. That means some of what
          you enter leaves our servers and is sent to a third-party model
          provider. This page states exactly which data that is, which features
          cause it, and how to stop it. What we store, and for how long, is
          covered separately in Privacy.
        </p>
      </section>

      <section>
        <h2>Photo analysis</h2>
        <p>
          When you analyse a meal photo, a downscaled copy of that photo is sent
          to the model provider to identify the foods and their portions. The
          original stays on your account so you can revisit the analysis. Nothing
          identifying you — your name, your email address, your account id — is
          sent with it.
        </p>
      </section>

      <section>
        <h2>Weekly insights</h2>
        <p>
          A weekly insight is generated from your own aggregate figures for that
          week, and the previous week when there is enough data. Only those
          numbers are sent. Individual photos are not.
        </p>
      </section>

      <section>
        <h2>The AI Coach</h2>
        <p>
          Each coach reply is generated from a bounded context: your nutrition
          figures, your meal names and the dates they fall on. Your name, email
          address, password hash, API keys, account identifiers, photos and body
          metrics are not part of it. Your side of the conversation is also sent,
          so treat the coach as you would any chat — do not type anything into it
          you would not want processed by a third party.
        </p>
      </section>

      <section>
        <h2>What never involves a model</h2>
        <p>
          Manual meal entry, the Smart Plate score and its optimizations, the
          NutriLens Tip, your streaks, and everything on Analytics and History
          are arithmetic over your own rows. They make no external call, so you
          can log and review a full day of meals without any data leaving
          NutriLens.
        </p>
      </section>

      <section>
        <h2>Optional information</h2>
        <p>
          The goal calculator asks for age, height, weight, activity level and
          biological sex because the formula behind it uses them. All of it is
          optional — you can set your calorie and macro targets directly instead,
          and the calculator still works without biological sex.
        </p>
      </section>

      <section>
        <h2>Withdrawing consent</h2>
        <p>
          Use manual entry rather than photo analysis and no photo is ever sent.
          Skip the coach and no conversation is sent. You can delete any meal,
          along with its photo, from Today or History at any time. To have your
          whole account removed, write to privacy@nutrilens.app.
        </p>
      </section>

      <section>
        <h2>Estimates, not advice</h2>
        <p>
          Consenting to AI analysis is not consenting to a medical opinion. Every
          figure NutriLens produces from a photograph is an approximation, and the
          Terms set out that it must not be used for clinical or dietary treatment
          decisions.
        </p>
      </section>

      <section>
        <h2>Contact</h2>
        <p>
          Questions about what is sent, or a request to withdraw consent, can be
          sent to privacy@nutrilens.app.
        </p>
      </section>
    </LegalPage>
  );
}
