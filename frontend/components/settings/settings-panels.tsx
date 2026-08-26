"use client";

import * as React from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { BookOpen, Code2, ExternalLink, Loader2, LogOut, Target } from "lucide-react";
import { toast } from "sonner";

import { ApiError } from "@/lib/api-client";
import { apiDocsUrl } from "@/lib/env";
import { userService } from "@/services";
import { useAuth } from "@/hooks/use-auth";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Separator } from "@/components/ui/separator";
import { ThemeSegmentedControl } from "@/components/layout/theme-toggle";
import { FieldError, FormError } from "@/components/shared/form-message";
import { initialsFor } from "@/components/layout/user-menu";

function SettingsCard({
  title,
  description,
  children,
}: {
  title: string;
  description?: string;
  children: React.ReactNode;
}) {
  return (
    <section className="rounded-2xl bg-card p-5 ring-1 ring-foreground/10 sm:p-6">
      <h2 className="font-heading text-[0.9375rem] font-semibold">{title}</h2>
      {description && (
        <p className="mt-1 text-sm leading-relaxed text-muted-foreground">
          {description}
        </p>
      )}
      <div className="mt-5">{children}</div>
    </section>
  );
}

export function SettingsPanels() {
  const router = useRouter();
  const { user, setUser, logout } = useAuth();

  const [name, setName] = React.useState(user?.name ?? "");
  const [nameError, setNameError] = React.useState<string | undefined>();
  const [formError, setFormError] = React.useState<string | null>(null);
  const [saving, setSaving] = React.useState(false);
  const [signingOut, setSigningOut] = React.useState(false);

  // Re-sync the field if the user object changes underneath us (e.g. after a
  // refresh()). Adjusting state during render rather than in an effect avoids a
  // second render pass. https://react.dev/reference/react/useState
  const [syncedName, setSyncedName] = React.useState(user?.name ?? "");
  if (user && user.name !== syncedName) {
    setSyncedName(user.name);
    setName(user.name);
  }

  if (!user) return null;

  const dirty = name.trim() !== user.name;

  const saveProfile = async () => {
    setFormError(null);
    setNameError(undefined);

    const trimmed = name.trim();

    if (trimmed.length < 2) {
      setNameError("Please enter your full name.");
      return;
    }

    setSaving(true);

    try {
      const { data } = await userService.updateProfile({ name: trimmed });
      setUser(data);
      toast.success("Profile updated.");
    } catch (error) {
      if (error instanceof ApiError && error.isValidation) {
        const message = error.fieldError("name");
        if (message) setNameError(message);
        else setFormError(error.message);
      } else {
        setFormError(
          error instanceof ApiError ? error.message : "Could not save your profile.",
        );
      }
    } finally {
      setSaving(false);
    }
  };

  const handleLogout = async () => {
    setSigningOut(true);
    await logout();
    toast.success("Signed out.");
    router.replace("/");
  };

  return (
    <div className="space-y-5">
      <FormError message={formError} />

      <SettingsCard
        title="Profile"
        description="Your name appears on your dashboard greeting."
      >
        <div className="flex items-center gap-4">
          <Avatar size="lg">
            {user.avatar_url && <AvatarImage src={user.avatar_url} alt="" />}
            <AvatarFallback className="bg-primary/12 text-sm font-semibold text-primary">
              {initialsFor(user.name)}
            </AvatarFallback>
          </Avatar>
          <div className="min-w-0">
            <p className="truncate text-sm font-semibold">{user.name}</p>
            <p className="truncate text-sm text-muted-foreground">{user.email}</p>
          </div>
        </div>

        <Separator className="my-5" />

        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-2">
            <Label htmlFor="settings-name">Full name</Label>
            <Input
              id="settings-name"
              value={name}
              autoComplete="name"
              aria-invalid={Boolean(nameError)}
              onChange={(event) => setName(event.target.value)}
            />
            <FieldError message={nameError} />
          </div>

          <div className="space-y-2">
            <Label htmlFor="settings-email">Email</Label>
            <Input id="settings-email" value={user.email} disabled readOnly />
            <p className="text-[0.75rem] text-muted-foreground">
              Changing your email arrives with account verification.
            </p>
          </div>
        </div>

        <div className="mt-5 flex justify-end">
          <Button
            onClick={() => void saveProfile()}
            disabled={saving || !dirty}
          >
            {saving ? (
              <>
                <Loader2 className="animate-spin" />
                Saving…
              </>
            ) : (
              "Save changes"
            )}
          </Button>
        </div>
      </SettingsCard>

      <SettingsCard
        title="Appearance"
        description="Choose a theme or follow your device setting. Your choice is remembered on this device."
      >
        <ThemeSegmentedControl />
      </SettingsCard>

      <SettingsCard
        title="Nutrition goals"
        description="Your goal and daily macro targets live on their own screen."
      >
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div className="flex items-center gap-3">
            <span className="flex size-10 items-center justify-center rounded-xl bg-primary/12 text-primary">
              <Target className="size-[1.125rem]" />
            </span>
            <div>
              <p className="text-sm font-semibold">
                {user.nutrition_goal?.goal_label ?? "No goal set"}
              </p>
              <p className="text-sm text-muted-foreground tabular-nums">
                {user.nutrition_goal
                  ? `${user.nutrition_goal.calorie_target.toLocaleString("en-US")} kcal · ${user.nutrition_goal.protein_target}P / ${user.nutrition_goal.carb_target}C / ${user.nutrition_goal.fat_target}F`
                  : "Set your daily targets to start tracking."}
              </p>
            </div>
          </div>
          <Button render={<Link href="/goals" />} variant="outline">
            Edit goals
          </Button>
        </div>
      </SettingsCard>

      <SettingsCard
        title="Developer"
        description="The NutriLens Partner API lets another product send a meal photo or a food list and get nutrition back."
      >
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div className="flex items-center gap-3">
            <span className="flex size-10 items-center justify-center rounded-xl bg-primary/12 text-primary">
              <Code2 className="size-[1.125rem]" />
            </span>
            <div>
              <p className="text-sm font-semibold">API keys</p>
              <p className="text-sm text-muted-foreground">
                Create a key, then explore the endpoints in the API reference.
              </p>
            </div>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button
              render={
                <a href={apiDocsUrl} target="_blank" rel="noreferrer noopener" />
              }
              variant="ghost"
            >
              <BookOpen />
              API reference
              <ExternalLink className="size-3.5" />
            </Button>
            <Button render={<Link href="/developer" />} variant="outline">
              Manage keys
            </Button>
          </div>
        </div>
      </SettingsCard>

      <SettingsCard
        title="Account"
        description="This device's session. Account deletion is not built yet, so it is deliberately not offered here."
      >
        <div className="flex flex-wrap items-center justify-between gap-4">
          <p className="text-sm text-muted-foreground">
            Signing out revokes this device&apos;s access token. Your other
            devices stay signed in.
          </p>
          <Button
            variant="destructive"
            onClick={() => void handleLogout()}
            disabled={signingOut}
          >
            <LogOut />
            {signingOut ? "Signing out…" : "Sign out"}
          </Button>
        </div>
      </SettingsCard>
    </div>
  );
}
