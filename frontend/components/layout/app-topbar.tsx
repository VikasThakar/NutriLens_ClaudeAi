"use client";

import { Logo } from "@/components/layout/logo";
import { ThemeToggle } from "@/components/layout/theme-toggle";
import { UserMenu } from "@/components/layout/user-menu";

/** Mobile-only header. On desktop the sidebar carries this chrome. */
export function AppTopbar() {
  return (
    <header className="sticky top-0 z-30 border-b border-border bg-background/90 backdrop-blur-md lg:hidden">
      <div className="flex h-14 items-center justify-between gap-3 px-4">
        <Logo href="/today" />
        <div className="flex items-center gap-1">
          <ThemeToggle />
          <UserMenu />
        </div>
      </div>
    </header>
  );
}
