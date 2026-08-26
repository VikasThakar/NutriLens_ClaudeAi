"use client";

import * as React from "react";
import Link from "next/link";
import { Menu } from "lucide-react";

import { cn } from "@/lib/utils";
import { MARKETING_NAV } from "@/lib/navigation";
import { useAuth } from "@/hooks/use-auth";
import { Button } from "@/components/ui/button";
import { Logo } from "@/components/layout/logo";
import { ThemeToggle } from "@/components/layout/theme-toggle";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet";

export function MarketingNav() {
  const { isAuthenticated, status } = useAuth();
  const [scrolled, setScrolled] = React.useState(false);
  const [open, setOpen] = React.useState(false);

  React.useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8);
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  const showApp = status === "authenticated" && isAuthenticated;

  return (
    <header
      className={cn(
        "sticky top-0 z-50 w-full transition-colors duration-300",
        scrolled
          ? "border-b border-border bg-background/85 backdrop-blur-md"
          : "border-b border-transparent",
      )}
    >
      <nav
        aria-label="Main"
        className="mx-auto flex h-16 w-full max-w-6xl items-center justify-between gap-4 px-5 lg:px-8"
      >
        <Logo />

        {/* Desktop links */}
        <div className="hidden items-center gap-1 md:flex">
          {MARKETING_NAV.map((item) => (
            <a
              key={item.href}
              href={item.href}
              className="rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
            >
              {item.label}
            </a>
          ))}
        </div>

        <div className="flex items-center gap-1.5">
          <ThemeToggle />

          {showApp ? (
            <Button render={<Link href="/today" />} size="sm">
              Open App
            </Button>
          ) : (
            <>
              <Button
                render={<Link href="/login" />}
                variant="ghost"
                size="sm"
                className="hidden sm:inline-flex"
              >
                Login
              </Button>
              <Button render={<Link href="/register" />} size="sm">
                Get Started
              </Button>
            </>
          )}

          {/* Mobile menu */}
          <Sheet open={open} onOpenChange={setOpen}>
            <SheetTrigger
              render={
                <Button
                  variant="ghost"
                  size="icon-sm"
                  aria-label="Open menu"
                  className="md:hidden"
                />
              }
            >
              <Menu className="size-5" />
            </SheetTrigger>

            <SheetContent side="right" className="w-[min(20rem,86vw)] p-0">
              <div className="flex h-full flex-col">
                <div className="border-b border-border px-5 py-4">
                  <SheetTitle className="text-left">
                    <Logo href={null} />
                  </SheetTitle>
                  <SheetDescription className="mt-1 text-left">
                    Snap your food. See your nutrition.
                  </SheetDescription>
                </div>

                <div className="flex flex-1 flex-col gap-1 p-3">
                  {/*
                    Plain anchors rather than `SheetClose`: these are in-page
                    links, and Base UI's Close is a button — wrapping an `<a>`
                    in it claims button semantics the anchor does not have. The
                    sheet is controlled, so closing it on click is a one-liner.
                  */}
                  {MARKETING_NAV.map((item) => (
                    <a
                      key={item.href}
                      href={item.href}
                      onClick={() => setOpen(false)}
                      className="flex h-11 items-center rounded-lg px-3 text-[0.9375rem] font-medium text-foreground transition-colors hover:bg-muted"
                    >
                      {item.label}
                    </a>
                  ))}
                </div>

                <div className="space-y-2 border-t border-border p-4 pb-safe">
                  {showApp ? (
                    <Button
                      render={<Link href="/today" />}
                      size="lg"
                      className="w-full"
                    >
                      Open App
                    </Button>
                  ) : (
                    <>
                      <Button
                        render={<Link href="/register" />}
                        size="lg"
                        className="w-full"
                      >
                        Get Started
                      </Button>
                      <Button
                        render={<Link href="/login" />}
                        variant="outline"
                        size="lg"
                        className="w-full"
                      >
                        Login
                      </Button>
                    </>
                  )}
                </div>
              </div>
            </SheetContent>
          </Sheet>
        </div>
      </nav>
    </header>
  );
}
