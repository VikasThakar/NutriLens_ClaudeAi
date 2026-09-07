import type { Metadata } from "next";

import { LegalPage } from "@/components/marketing/legal-page";

export const metadata: Metadata = {
  title: "Maintenance",
  description:
    "How NutriLens handles planned maintenance, outages and AI provider incidents, and what happens to your meals while the service is unavailable.",
};

export default function MaintenancePage() {
  return (
    <LegalPage title="Maintenance" updated="7 September 2026">
      <section>
        <h2>Why this page exists</h2>
        <p>
          NutriLens is a web app talking to an API, and the API occasionally has
          to be taken down to be upgraded. This page sets out what happens when
          it is, what you can expect to still work, and what happens to the
          meals and photos you have already logged. It sits alongside the
          Privacy policy, which describes what we store, and the Terms, which
          govern your use of the service.
        </p>
      </section>

      <section>
        <h2>Planned maintenance</h2>
        <p>
          Planned work — a database migration, a dependency upgrade, a change to
          how meal images are stored — is scheduled outside peak logging hours
          and announced in the app beforehand. We do not commit to a fixed
          window or a recurring slot; the announcement is the notice.
        </p>
      </section>

      <section>
        <h2>What stops working</h2>
        <p>
          During a full maintenance window the API is unreachable, so signing in,
          logging a meal, editing one, and loading your dashboard, history,
          analytics and insights all fail. Pages that are already open may keep
          rendering the data they loaded before the window started; anything
          that needs a fresh request will show an error rather than stale
          numbers.
        </p>
        <p>
          Narrower maintenance affects only part of the product. Photo analysis,
          the AI coach and weekly insights all depend on a third-party AI
          provider, and work on that integration can leave the rest of the app —
          manual meal entry, history, goals — fully usable.
        </p>
      </section>

      <section>
        <h2>Meals in progress</h2>
        <p>
          A meal you are part-way through adding is held in the browser and is
          not on your account until you save it. If the API goes down before you
          save, or you reload the page, that in-progress meal is lost and the
          photo has to be submitted again. Nothing already saved is affected.
        </p>
      </section>

      <section>
        <h2>Your data during maintenance</h2>
        <p>
          Maintenance does not delete anything. Your meals, photos, goals,
          insights and coach conversations stay on your account and are there
          when the service returns. If a window ends up interrupting a write —
          a meal saved at the moment the API went away — it either completed or
          it did not; there is no partial meal to clean up.
        </p>
      </section>

      <section>
        <h2>Unplanned outages</h2>
        <p>
          Unplanned outages are not announced in advance, by definition. If the
          app is throwing errors on requests that normally work, treat it as an
          outage rather than something wrong with your account, and try again
          later. Signing out and back in does not help and will leave you unable
          to sign in until the API is back.
        </p>
      </section>

      <section>
        <h2>AI provider incidents</h2>
        <p>
          When the AI provider is degraded, photo analysis, coach replies and
          weekly insight generation are the first things to fail — usually as a
          timeout or an &ldquo;unavailable&rdquo; error on a single request while
          everything else behaves normally. Retrying a failed analysis is safe;
          a failed analysis does not create a meal. You can also enter the meal
          manually and skip the analysis entirely.
        </p>
      </section>

      <section>
        <h2>The partner API</h2>
        <p>
          Integrations calling the versioned partner API see the same windows.
          API keys are not revoked or rotated by maintenance and keep working
          afterwards, but requests during a window will fail. Treat a 5xx as
          retryable with backoff rather than as a rejected payload, and do not
          re-issue a key in response to an outage.
        </p>
      </section>

      <section>
        <h2>Checking whether it is us</h2>
        <p>
          The API exposes an unauthenticated health endpoint that answers
          without touching your account, which is the quickest way to tell an
          outage apart from a problem on your side. If it responds and the app
          still does not work for you, it is worth reporting.
        </p>
      </section>

      <section>
        <h2>Contact</h2>
        <p>
          Problems that look like an outage, or questions about a window you
          have been told about, can be sent to support@nutrilens.app.
        </p>
      </section>
    </LegalPage>
  );
}
