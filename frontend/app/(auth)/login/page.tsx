import type { Metadata } from "next";

import { LoginForm } from "@/components/auth/login-form";

export const metadata: Metadata = {
  title: "Sign in",
  description: "Sign in to your NutriLens account.",
};

export default function LoginPage() {
  return (
    <div className="animate-rise">
      <header className="mb-8">
        <h1 className="font-heading text-2xl font-semibold sm:text-3xl">
          Welcome back
        </h1>
        <p className="mt-2 text-sm text-muted-foreground">
          Sign in to pick up where you left off.
        </p>
      </header>

      <LoginForm />
    </div>
  );
}
