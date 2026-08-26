import Link from "next/link";
import { ArrowLeft, Compass } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Logo } from "@/components/layout/logo";

export default function NotFound() {
  return (
    <div className="flex min-h-dvh flex-1 flex-col">
      <header className="px-5 py-4 lg:px-8">
        <Logo />
      </header>

      <main className="flex flex-1 items-center justify-center px-5 pb-16">
        <div className="max-w-md text-center">
          <span className="mx-auto flex size-14 items-center justify-center rounded-2xl bg-primary/12 text-primary">
            <Compass className="size-6" />
          </span>

          <p className="mt-6 font-mono text-sm font-medium text-muted-foreground">
            404
          </p>
          <h1 className="mt-2 font-heading text-2xl font-semibold sm:text-3xl">
            This page isn&apos;t on the plate
          </h1>
          <p className="mt-3 text-sm leading-relaxed text-muted-foreground">
            The link may be out of date, or the page may have moved as NutriLens
            grows.
          </p>

          <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:justify-center">
            <Button render={<Link href="/" />} size="lg">
              <ArrowLeft />
              Back to home
            </Button>
            <Button render={<Link href="/today" />} variant="outline" size="lg">
              Go to dashboard
            </Button>
          </div>
        </div>
      </main>
    </div>
  );
}
