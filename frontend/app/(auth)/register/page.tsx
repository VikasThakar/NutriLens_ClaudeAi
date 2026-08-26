import type { Metadata } from "next";

import { RegisterForm } from "@/components/auth/register-form";

export const metadata: Metadata = {
  title: "Create your account",
  description: "Create a NutriLens account and start tracking your nutrition.",
};

export default function RegisterPage() {
  return (
    <div className="animate-rise">
      <header className="mb-8">
        <h1 className="font-heading text-2xl font-semibold sm:text-3xl">
          Create your account
        </h1>
        <p className="mt-2 text-sm text-muted-foreground">
          Set your goals once, then log meals in seconds.
        </p>
      </header>

      <RegisterForm />
    </div>
  );
}
