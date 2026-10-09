import type { Metadata } from "next";

import { LegalPage } from "@/components/marketing/legal-page";

export const metadata: Metadata = {
  title: "Privacy Policy",
  description:
    "What NutriLens stores about you, why, who it is shared with, and how to have it removed.",
};

export default function PrivacyPage() {
  return (
    <LegalPage title="Privacy Policy" updated="9 October 2026">
      <section>
        <h2>What this page covers</h2>
        <p>
          This policy describes the information NutriLens keeps when you use the
          app, what it is used for, and who else sees it. What is sent to an AI
          model, and how to avoid sending it, is set out in detail on the
          Consent page.
        </p>
      </section>

      <section>
        <h2>Your account</h2>
        <p>
          To create an account we store your name, your email address and a
          one-way hash of your password — never the password itself. We also
          keep your timezone so that &ldquo;today&rdquo; means your day, not the
          server&apos;s.
        </p>
      </section>

      <section>
        <h2>Your meals and photos</h2>
        <p>
          Every meal you log is stored with its foods, portions, calories and
          macronutrients, the time you ate it, and any corrections you make to
          an AI estimate. When you analyse a photo, the original image is kept on
          your account so you can revisit the analysis later.
        </p>
      </section>

      <section>
        <h2>Goals and body metrics</h2>
        <p>
          Your calorie and macro targets are stored, along with the history of
          previous targets. Age, height, weight, activity level and biological
          sex are only stored if you choose to enter them for the goal
          calculator. All of them are optional.
        </p>
      </section>

      <section>
        <h2>Insights and the AI Coach</h2>
        <p>
          Weekly insights you generate are saved so you can read them again.
          Coach conversations — your messages and the replies — are stored so you
          can return to a thread, and you can delete a conversation at any time
          from the Coach screen.
        </p>
      </section>

      <section>
        <h2>Developer API keys</h2>
        <p>
          If you create an API key, we store a hash of it and a short prefix so
          you can recognise it. The full key is shown once and cannot be
          recovered. Photos sent through the partner API are processed in memory
          and are not stored.
        </p>
      </section>

      <section>
        <h2>How your information is used</h2>
        <p>
          Your information is used to run NutriLens for you: to estimate the
          nutrition in your meals, to show your dashboard, history, analytics and
          streaks, to generate your insights and coach replies, and to keep you
          signed in. It is not used to build advertising profiles.
        </p>
      </section>

      <section>
        <h2>AI model providers</h2>
        <p>
          Photo analysis, weekly insights and the AI Coach rely on a third-party
          AI model provider. Only the data each feature needs is sent — a
          downscaled photo, aggregate figures, or a bounded nutrition context —
          and your name, email address and account identifiers are never part of
          it. The Consent page lists exactly what is sent for each feature.
        </p>
      </section>

      <section>
        <h2>Cookies and local storage</h2>
        <p>
          NutriLens sets one cookie, which holds your sign-in token and is
          removed when you sign out. Your light or dark theme preference is kept
          in your browser&apos;s local storage. There are no advertising or
          third-party tracking cookies.
        </p>
      </section>

      <section>
        <h2>Deleting your data</h2>
        <p>
          You can delete a meal from Today or History, and a coach conversation
          from the Coach screen. Deleting a meal removes it from your account
          views; the record and its photo are only erased permanently when your
          account is removed. To have your whole account and everything
          associated with it removed, write to privacy@nutrilens.app.
        </p>
      </section>

      <section>
        <h2>Security</h2>
        <p>
          Passwords and API keys are stored only as hashes. Every request for
          your data is checked against your account, so no other user — and no
          partner application, including one using your own API key — can read
          it.
        </p>
      </section>

      <section>
        <h2>Changes</h2>
        <p>
          This policy may change as the product develops. Material changes will
          be announced in the app, and the date at the top of this page will be
          updated.
        </p>
      </section>

      <section>
        <h2>Contact</h2>
        <p>
          Questions about this policy, or a request to access or remove your
          data, can be sent to privacy@nutrilens.app.
        </p>
      </section>
    </LegalPage>
  );
}
