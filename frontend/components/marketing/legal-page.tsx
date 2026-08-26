import type * as React from "react";

import { MarketingFooter } from "@/components/marketing/marketing-footer";
import { MarketingNav } from "@/components/marketing/marketing-nav";

/**
 * Shared shell for the Privacy / Terms pages. Content is intentionally short —
 * these exist so no footer link is broken, and so real policy copy has a home.
 */
export function LegalPage({
  title,
  updated,
  children,
}: {
  title: string;
  updated: string;
  children: React.ReactNode;
}) {
  return (
    <>
      <MarketingNav />
      <main className="flex-1">
        <div className="mx-auto w-full max-w-3xl px-5 py-16 lg:px-8 lg:py-24">
          <h1 className="font-heading text-3xl font-semibold sm:text-4xl">
            {title}
          </h1>
          <p className="mt-3 text-sm text-muted-foreground">
            Last updated {updated}
          </p>

          <div className="mt-10 space-y-8 text-sm leading-relaxed text-muted-foreground [&_h2]:font-heading [&_h2]:text-base [&_h2]:font-semibold [&_h2]:text-foreground [&_p]:mt-2">
            {children}
          </div>
        </div>
      </main>
      <MarketingFooter />
    </>
  );
}
