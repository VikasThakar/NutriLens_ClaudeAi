"use client";

import * as React from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { ArrowRight, Loader2 } from "lucide-react";
import { toast } from "sonner";

import { ApiError } from "@/lib/api-client";
import { loginSchema, type LoginValues } from "@/lib/validations";
import { useAuth } from "@/hooks/use-auth";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { PasswordInput } from "@/components/auth/password-input";
import { FieldError, FormError } from "@/components/shared/form-message";

export function LoginForm() {
  const router = useRouter();
  const { login } = useAuth();
  const [formError, setFormError] = React.useState<string | null>(null);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<LoginValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: "", password: "" },
  });

  const onSubmit = async (values: LoginValues) => {
    setFormError(null);

    try {
      const user = await login(values);
      toast.success(`Welcome back, ${user.name.split(" ")[0]}.`);
      router.replace(user.has_onboarded ? "/today" : "/onboarding");
    } catch (error) {
      if (error instanceof ApiError) {
        // Surface Laravel's field errors on the matching inputs.
        if (error.isValidation) {
          let matched = false;
          for (const field of ["email", "password"] as const) {
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
        <Label htmlFor="email">Email</Label>
        <Input
          id="email"
          type="email"
          inputMode="email"
          autoComplete="email"
          autoFocus
          placeholder="you@example.com"
          aria-invalid={Boolean(errors.email)}
          {...register("email")}
        />
        <FieldError message={errors.email?.message} />
      </div>

      <div className="space-y-2">
        <div className="flex items-center justify-between gap-2">
          <Label htmlFor="password">Password</Label>
          <Link
            href="/forgot-password"
            className="text-[0.8125rem] font-medium text-primary hover:underline"
          >
            Forgot password?
          </Link>
        </div>
        <PasswordInput
          id="password"
          autoComplete="current-password"
          placeholder="Your password"
          aria-invalid={Boolean(errors.password)}
          {...register("password")}
        />
        <FieldError message={errors.password?.message} />
      </div>

      <Button type="submit" size="lg" className="w-full" disabled={isSubmitting}>
        {isSubmitting ? (
          <>
            <Loader2 className="animate-spin" />
            Signing in…
          </>
        ) : (
          <>
            Sign in
            <ArrowRight />
          </>
        )}
      </Button>

      <p className="text-center text-sm text-muted-foreground">
        New to NutriLens?{" "}
        <Link href="/register" className="font-medium text-primary hover:underline">
          Create an account
        </Link>
      </p>
    </form>
  );
}
