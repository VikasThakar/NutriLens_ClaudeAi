import type { Metadata } from "next";
import Link from "next/link";
import { ArrowLeft, MailQuestion } from "lucide-react";

import { Button } from "@/components/ui/button";

export const metadata: Metadata = {
  title: "Reset your password",
  description: "Recover access to your NutriLens account.",
};

export default function ForgotPasswordPage() {
  return (
    <div className="animate-rise">
      <span className="flex size-11 items-center justify-center rounded-xl bg-primary/12 text-primary">
        <MailQuestion className="size-5" />
      </span>

      <header className="mt-5 mb-6">
        <h1 className="font-heading text-2xl font-semibold sm:text-3xl">
          Reset your password
        </h1>
        <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
          Password reset emails are not wired up yet — they arrive with the
          notification work in a later phase. For now, contact
          support@nutrilens.app and we will restore access to your account.
        </p>
      </header>

      <Button
        render={<Link href="/login" />}
        variant="outline"
        size="lg"
        className="w-full"
      >
        <ArrowLeft />
        Back to sign in
      </Button>
    </div>
  );
}
