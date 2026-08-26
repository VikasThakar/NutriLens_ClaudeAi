"use client";

import * as React from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { ArrowRight, Check, Loader2 } from "lucide-react";
import { toast } from "sonner";

import { cn } from "@/lib/utils";
import { ApiError } from "@/lib/api-client";
import {
  PASSWORD_RULES,
  registerSchema,
  type RegisterValues,
} from "@/lib/validations";
import { useAuth } from "@/hooks/use-auth";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { PasswordInput } from "@/components/auth/password-input";
import { FieldError, FormError } from "@/components/shared/form-message";

const SERVER_FIELDS = ["name", "email", "password"] as const;

export function RegisterForm() {
  const router = useRouter();
  const { register: registerAccount } = useAuth();
  const [formError, setFormError] = React.useState<string | null>(null);

  // Mirrored locally rather than read through RHF's `watch`, which subscribes
  // outside React's render cycle.
  const [password, setPassword] = React.useState("");

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<RegisterValues>({
    resolver: zodResolver(registerSchema),
    defaultValues: {
      name: "",
      email: "",
      password: "",
      password_confirmation: "",
    },
    mode: "onTouched",
  });

  const onSubmit = async (values: RegisterValues) => {
    setFormError(null);

    try {
      const user = await registerAccount(values);
      toast.success("Account created. Let's set up your goals.");
      router.replace(user.has_onboarded ? "/today" : "/onboarding");
    } catch (error) {
      if (error instanceof ApiError) {
        if (error.isValidation) {
          let matched = false;
          for (const field of SERVER_FIELDS) {
            const message = error.fieldError(field);
            if (message) {
              setError(field, { type: "server", message });
              matched = true;
            }
          }
          if (!matched) setFormError(error.message);
          return;
        }

        setFormError(error.message);
        return;
      }

      setFormError("Something went wrong. Please try again.");
    }
  };

  return (
    <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-5">
      <FormError message={formError} />

      <div className="space-y-2">
        <Label htmlFor="name">Full name</Label>
        <Input
          id="name"
          autoComplete="name"
          autoFocus
          placeholder="Alex Morgan"
          aria-invalid={Boolean(errors.name)}
          {...register("name")}
        />
        <FieldError message={errors.name?.message} />
      </div>

      <div className="space-y-2">
        <Label htmlFor="email">Email</Label>
        <Input
          id="email"
          type="email"
          inputMode="email"
          autoComplete="email"
          placeholder="you@example.com"
          aria-invalid={Boolean(errors.email)}
          {...register("email")}
        />
        <FieldError message={errors.email?.message} />
      </div>

      <div className="space-y-2">
        <Label htmlFor="password">Password</Label>
        <PasswordInput
          id="password"
          autoComplete="new-password"
          placeholder="At least 8 characters"
          aria-invalid={Boolean(errors.password)}
          {...register("password", {
            onChange: (event) => setPassword(event.target.value),
          })}
        />

        {/* Live requirement checklist — replaces a wall of error text. */}
        <ul className="mt-2.5 grid gap-1.5 sm:grid-cols-3">
          {PASSWORD_RULES.map((rule) => {
            const met = rule.test(password);
            return (
              <li
                key={rule.label}
                className={cn(
                  "flex items-center gap-1.5 text-[0.75rem] transition-colors",
                  met ? "text-primary" : "text-muted-foreground",
                )}
              >
                <span
                  aria-hidden="true"
                  className={cn(
                    "flex size-3.5 shrink-0 items-center justify-center rounded-full border transition-colors",
                    met
                      ? "border-primary bg-primary text-primary-foreground"
                      : "border-border",
                  )}
                >
                  {met && <Check className="size-2.5" strokeWidth={3} />}
                </span>
                {rule.label}
              </li>
            );
          })}
        </ul>

        <FieldError message={errors.password?.message} />
      </div>

      <div className="space-y-2">
        <Label htmlFor="password_confirmation">Confirm password</Label>
        <PasswordInput
          id="password_confirmation"
          autoComplete="new-password"
          placeholder="Re-enter your password"
          aria-invalid={Boolean(errors.password_confirmation)}
          {...register("password_confirmation")}
        />
        <FieldError message={errors.password_confirmation?.message} />
      </div>

      <Button type="submit" size="lg" className="w-full" disabled={isSubmitting}>
        {isSubmitting ? (
          <>
            <Loader2 className="animate-spin" />
            Creating account…
          </>
        ) : (
          <>
            Create account
            <ArrowRight />
          </>
        )}
      </Button>

      <p className="text-center text-xs leading-relaxed text-muted-foreground">
        By creating an account you agree to our{" "}
        <Link href="/terms" className="text-foreground hover:underline">
          Terms
        </Link>{" "}
        and{" "}
        <Link href="/privacy" className="text-foreground hover:underline">
          Privacy Policy
        </Link>
        .
      </p>

      <p className="text-center text-sm text-muted-foreground">
        Already have an account?{" "}
        <Link href="/login" className="font-medium text-primary hover:underline">
          Sign in
        </Link>
      </p>
    </form>
  );
}
