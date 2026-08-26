"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

import { cn } from "@/lib/utils";
import { SIDEBAR_NAV } from "@/lib/navigation";
import { Logo } from "@/components/layout/logo";
import { ThemeToggle } from "@/components/layout/theme-toggle";
import { UserMenu } from "@/components/layout/user-menu";

export function AppSidebar() {
  const pathname = usePathname();

  return (
    <aside className="fixed inset-y-0 left-0 z-40 hidden w-64 flex-col border-r border-sidebar-border bg-sidebar lg:flex">
      <div className="flex h-16 shrink-0 items-center px-5">
        <Logo href="/today" />
      </div>

      <nav aria-label="Primary" className="flex-1 overflow-y-auto px-3 py-2">
        <ul className="space-y-1">
          {SIDEBAR_NAV.map((item) => {
            const active = pathname === item.href;

            return (
              <li key={item.href}>
                <Link
                  href={item.href}
                  aria-current={active ? "page" : undefined}
                  className={cn(
                    "group relative flex h-10 items-center gap-2.5 rounded-lg px-3 text-sm font-medium transition-colors",
                    "focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
                    active
                      ? "bg-sidebar-accent text-sidebar-accent-foreground"
                      : "text-muted-foreground hover:bg-sidebar-accent/60 hover:text-sidebar-accent-foreground",
                  )}
                >
                  {/* Active rail */}
                  <span
                    aria-hidden="true"
                    className={cn(
                      "absolute top-1/2 left-0 h-5 w-0.5 -translate-y-1/2 rounded-r-full bg-primary transition-opacity",
                      active ? "opacity-100" : "opacity-0",
                    )}
                  />
                  <item.icon
                    className={cn(
                      "size-[1.125rem] shrink-0 transition-colors",
                      active && "text-primary",
                    )}
                  />
                  <span className="flex-1 truncate">{item.label}</span>
                  {item.soon && (
                    <span className="rounded-full bg-muted px-1.5 py-0.5 text-[0.5625rem] font-semibold tracking-wide text-muted-foreground uppercase">
                      Soon
                    </span>
                  )}
                </Link>
              </li>
            );
          })}
        </ul>
      </nav>

      <div className="shrink-0 border-t border-sidebar-border p-3">
        <div className="mb-1 flex items-center justify-between gap-2 px-2 py-1">
          <span className="text-[0.6875rem] font-medium tracking-wide text-muted-foreground uppercase">
            Appearance
          </span>
          <ThemeToggle />
        </div>
        <UserMenu variant="full" align="start" side="top" />
      </div>
    </aside>
  );
}
