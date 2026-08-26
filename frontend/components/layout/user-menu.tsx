"use client";

import * as React from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { ChevronsUpDown, Code2, LogOut, Settings, Target } from "lucide-react";
import { toast } from "sonner";

import { cn } from "@/lib/utils";
import { useAuth } from "@/hooks/use-auth";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

export function initialsFor(name: string): string {
  return name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("");
}

interface UserMenuProps {
  /** `full` shows name + email inline (sidebar); `compact` is avatar-only. */
  variant?: "full" | "compact";
  align?: "start" | "center" | "end";
  side?: "top" | "bottom" | "left" | "right";
  className?: string;
}

export function UserMenu({
  variant = "compact",
  align = "end",
  side = "bottom",
  className,
}: UserMenuProps) {
  const router = useRouter();
  const { user, logout } = useAuth();
  const [signingOut, setSigningOut] = React.useState(false);

  if (!user) return null;

  const handleLogout = async () => {
    setSigningOut(true);
    await logout();
    toast.success("Signed out.");
    router.replace("/");
  };

  const avatar = (
    <Avatar size={variant === "full" ? "default" : "default"}>
      {user.avatar_url && <AvatarImage src={user.avatar_url} alt="" />}
      <AvatarFallback className="bg-primary/12 text-xs font-semibold text-primary">
        {initialsFor(user.name)}
      </AvatarFallback>
    </Avatar>
  );

  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        render={
          <button
            type="button"
            aria-label="Account menu"
            className={cn(
              "flex items-center gap-2.5 rounded-xl transition-colors",
              "focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
              variant === "full"
                ? "w-full p-2 text-left hover:bg-sidebar-accent"
                : "p-0.5 hover:opacity-85",
              className,
            )}
          />
        }
      >
        {avatar}
        {variant === "full" && (
          <>
            <span className="min-w-0 flex-1">
              <span className="block truncate text-[0.8125rem] font-semibold">
                {user.name}
              </span>
              <span className="block truncate text-[0.75rem] text-muted-foreground">
                {user.email}
              </span>
            </span>
            <ChevronsUpDown className="size-4 shrink-0 text-muted-foreground" />
          </>
        )}
      </DropdownMenuTrigger>

      <DropdownMenuContent align={align} side={side} className="w-60 min-w-60">
        <DropdownMenuLabel className="px-2 py-2">
          <span className="block truncate text-[0.8125rem] font-semibold text-foreground">
            {user.name}
          </span>
          <span className="block truncate text-[0.75rem] font-normal text-muted-foreground">
            {user.email}
          </span>
        </DropdownMenuLabel>

        <DropdownMenuSeparator />

        <DropdownMenuItem
          className="h-9 gap-2 px-2"
          render={<Link href="/goals" />}
        >
          <Target className="size-4" />
          Goals &amp; targets
        </DropdownMenuItem>
        <DropdownMenuItem
          className="h-9 gap-2 px-2"
          render={<Link href="/developer" />}
        >
          <Code2 className="size-4" />
          API keys
        </DropdownMenuItem>
        <DropdownMenuItem
          className="h-9 gap-2 px-2"
          render={<Link href="/settings" />}
        >
          <Settings className="size-4" />
          Settings
        </DropdownMenuItem>

        <DropdownMenuSeparator />

        <DropdownMenuItem
          variant="destructive"
          className="h-9 gap-2 px-2"
          disabled={signingOut}
          onClick={() => void handleLogout()}
        >
          <LogOut className="size-4" />
          {signingOut ? "Signing out…" : "Sign out"}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
