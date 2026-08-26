import type { Route } from "next";
import Link from "next/link";

import { Logo } from "@/components/layout/logo";

type FooterLink =
  /** In-page anchor on the landing page. */
  | { label: string; hash: string }
  /** Internal app route. */
  | { label: string; route: Route }
  /** Placeholder for a surface that ships in a later phase. */
  | { label: string; soon: true };

const COLUMNS: { heading: string; links: FooterLink[] }[] = [
  {
    heading: "Product",
    links: [
      { label: "Features", hash: "#features" },
      { label: "How It Works", hash: "#how-it-works" },
      { label: "Get Started", route: "/register" },
      { label: "Login", route: "/login" },
    ],
  },
  {
    heading: "Developers",
    links: [
      { label: "API", soon: true },
      { label: "Webhooks", soon: true },
    ],
  },
  {
    heading: "Legal",
    links: [
      { label: "Privacy", route: "/privacy" },
      { label: "Terms", route: "/terms" },
    ],
  },
];

const linkClasses =
  "text-sm text-muted-foreground transition-colors hover:text-foreground";

function FooterLinkItem({ link }: { link: FooterLink }) {
  if ("soon" in link) {
    return (
      <span className="inline-flex items-center gap-2 text-sm text-muted-foreground/70">
        {link.label}
        <span className="rounded-full bg-muted px-1.5 py-0.5 text-[0.625rem] font-medium tracking-wide text-muted-foreground uppercase">
          Soon
        </span>
      </span>
    );
  }

  if ("hash" in link) {
    return (
      <a href={link.hash} className={linkClasses}>
        {link.label}
      </a>
    );
  }

  return (
    <Link href={link.route} className={linkClasses}>
      {link.label}
    </Link>
  );
}

export function MarketingFooter() {
  return (
    <footer className="border-t border-border bg-muted/30">
      <div className="mx-auto w-full max-w-6xl px-5 py-14 lg:px-8">
        <div className="grid gap-10 sm:grid-cols-2 lg:grid-cols-[1.4fr_repeat(3,1fr)]">
          <div className="max-w-xs">
            <Logo />
            <p className="mt-4 text-sm leading-relaxed text-muted-foreground">
              AI-powered macronutrient tracking. Photograph your meal, review the
              estimate, and watch the week add up.
            </p>
          </div>

          {COLUMNS.map((column) => (
            <nav key={column.heading} aria-label={column.heading}>
              <h2 className="text-[0.8125rem] font-semibold tracking-wide text-foreground uppercase">
                {column.heading}
              </h2>
              <ul className="mt-4 space-y-2.5">
                {column.links.map((link) => (
                  <li key={link.label}>
                    <FooterLinkItem link={link} />
                  </li>
                ))}
              </ul>
            </nav>
          ))}
        </div>

        <div className="mt-12 flex flex-col items-start justify-between gap-3 border-t border-border pt-6 sm:flex-row sm:items-center">
          <p className="text-xs text-muted-foreground">
            © {new Date().getFullYear()} NutriLens. All rights reserved.
          </p>
          <p className="text-xs text-muted-foreground">
            Nutrition estimates are approximations, not medical advice.
          </p>
        </div>
      </div>
    </footer>
  );
}
