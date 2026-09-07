import type { Metadata } from "next";

import { LegalPage } from "@/components/marketing/legal-page";

export const metadata: Metadata = {
  title: "Consent",
  description:
    "What you agree to when NutriLens processes your meal photos, health details and coach conversations.",
};

export default function ConsentPage() {
  return (
    <LegalPage title="Consent" updated="7 September 2026">
      <section>
        <h2>Why this page exists</h2>
        <p>
          NutriLens cannot work without processing information about your body
          and the food you eat. This page sets out, in plain terms, what you are
          agreeing to when you create an account and use the app, and how to
          take that agreement back. It sits alongside the Privacy policy, which
          describes what we store, and the Terms, which govern your use of the
          service.
        </p>
      </section>

      <section>
        <h2>What you are consenting to</h2>
        <p>
          By creating an account and logging meals you consent to us processing
          the account details you provide, the meals and photos you submit, the
          nutrition goals you set, and the messages you send to the AI coach —
          for the purpose of running the service for you.
        </p>
      </section>

      <section>
        <h2>Meal photos and AI analysis</h2>
        <p>
          When you submit a meal photo, the image and any notes you add are sent
          to a third-party AI provider so the food can be identified and
          estimated. The same applies to portion re-estimates, weekly insights
          and coach replies, which are generated from the meal data on your
          account. The provider is chosen by configuration rather than per
          account, so treat any meal photo you upload as data that leaves our
          infrastructure for processing.
        </p>
        <p>
          Do not photograph anything you are not comfortable sending to an
          external analysis service — including people, documents or screens
          that happen to be in frame.
        </p>
      </section>

      <section>
        <h2>Health and body details</h2>
        <p>
          Your age, height, weight, biological sex and activity level are
          optional. If you provide them, they are used to calculate calorie and
          macronutrient targets and to shape coach and insight responses.
          Leaving them blank means goals have to be entered manually; it does not
          block the rest of the app.
        </p>
      </section>

      <section>
        <h2>Coach conversations</h2>
        <p>
          Coach conversations are kept against your account so a thread can be
          continued later, and recent meal and goal data is included as context
          when a reply is generated. Coach output is an estimate-driven
          suggestion, not medical or dietary advice.
        </p>
      </section>

      <section>
        <h2>Cookies and session storage</h2>
        <p>
          A single cookie holds your session token so you stay signed in and so
          protected pages can be gated before they render. It is set by us, not
          by an advertiser, and it is cleared when you log out. There is no
          advertising or cross-site tracking in NutriLens, so there is no
          tracking consent to give or refuse.
        </p>
      </section>

      <section>
        <h2>Developer API keys</h2>
        <p>
          If you create an API key, anything calling the API with that key acts
          on your account and reaches the same meal, goal and image data you see
          in the app. Sharing a key extends your consent to whoever holds it, so
          revoke keys you no longer recognise.
        </p>
      </section>

      <section>
        <h2>Withdrawing consent</h2>
        <p>
          You can delete individual meals and their photos at any time, clear the
          optional body details from your profile, or delete your account, which
          removes your meals, images, goals and insights. Withdrawing consent
          does not undo analysis that has already happened, and it does not
          reach copies held by an AI provider under its own retention rules.
        </p>
      </section>

      <section>
        <h2>Changes</h2>
        <p>
          If the way we process your data changes materially — a new category of
          data, or a new purpose — this page will be updated and the change
          announced in the app.
        </p>
      </section>

      <section>
        <h2>Contact</h2>
        <p>
          Questions about consent, or a request to have your data removed, can be
          sent to privacy@nutrilens.app.
        </p>
      </section>
    </LegalPage>
  );
}
