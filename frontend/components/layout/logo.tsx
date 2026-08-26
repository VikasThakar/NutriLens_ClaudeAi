import type { Route } from "next";
import Link from "next/link";

import { cn } from "@/lib/utils";

/**
 * NutriLens mark: a camera lens ring enclosing a leaf — the two halves of the
 * product in one glyph.
 */
export function LogoMark({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 32 32"
      fill="none"
      aria-hidden="true"
      className={cn("size-8", className)}
    >
      <circle
        cx="16"
        cy="16"
        r="13"
        stroke="currentColor"
        strokeWidth="2.1"
        strokeOpacity="0.32"
      />
      <path
        d="M9.9 22.1c0-6.2 5-11.2 11.2-11.2.6 0 1.1 0 1.6.1.1.5.1 1 .1 1.6 0 6.2-5 11.2-11.2 11.2-.6 0-1.1 0-1.6-.1a11 11 0 0 1-.1-1.6Z"
        fill="currentColor"
      />
      <path
        d="M11.4 20.6 20.9 11.1"
        stroke="var(--background)"
        strokeWidth="1.6"
        strokeLinecap="round"
        strokeOpacity="0.55"
      />
    </svg>
  );
}

interface LogoProps {
  className?: string;
  markClassName?: string;
  /** Render as a link to the given href. Pass null for a plain, static mark. */
  href?: Route | null;
  showWordmark?: boolean;
}

export function Logo({
  className,
  markClassName,
  href = "/",
  showWordmark = true,
}: LogoProps) {
  const content = (
    <>
      <LogoMark className={cn("text-primary", markClassName)} />
      {showWordmark && (
        <span className="font-heading text-[1.0625rem] font-semibold tracking-tight">
          Nutri<span className="text-primary">Lens</span>
        </span>
      )}
    </>
  );

  const classes = cn(
    "inline-flex items-center gap-2 transition-opacity hover:opacity-85",
    "focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none rounded-lg",
    className,
  );

  if (!href) {
    return <span className={classes}>{content}</span>;
  }

  return (
    <Link href={href} className={classes} aria-label="NutriLens home">
      {content}
    </Link>
  );
}