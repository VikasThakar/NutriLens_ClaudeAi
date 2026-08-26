import { z } from "zod";

/**
 * Client-side schemas. These mirror the Laravel Form Requests so the user gets
 * instant feedback — the server remains the authority and its 422 responses are
 * always surfaced too.
 */

export const PASSWORD_RULES = [
  { label: "At least 8 characters", test: (v: string) => v.length >= 8 },
  { label: "Contains a letter", test: (v: string) => /[A-Za-z]/.test(v) },
  { label: "Contains a number", test: (v: string) => /\d/.test(v) },
] as const;

const passwordSchema = z
  .string()
  .min(8, "Password must be at least 8 characters.")
  .regex(/[A-Za-z]/, "Password must contain at least one letter.")
  .regex(/\d/, "Password must contain at least one number.");

export const loginSchema = z.object({
  email: z
    .string()
    .min(1, "Email is required.")
    .pipe(z.email("Enter a valid email address.")),
  password: z.string().min(1, "Password is required."),
});

export type LoginValues = z.infer<typeof loginSchema>;

export const registerSchema = z
  .object({
    name: z
      .string()
      .trim()
      .min(2, "Please enter your full name.")
      .max(120, "That name is too long."),
    email: z
      .string()
      .min(1, "Email is required.")
      .pipe(z.email("Enter a valid email address.")),
    password: passwordSchema,
    password_confirmation: z.string().min(1, "Please confirm your password."),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: "The passwords do not match.",
    path: ["password_confirmation"],
  });

export type RegisterValues = z.infer<typeof registerSchema>;

export const goalTypeSchema = z.enum([
  "lose_weight",
  "maintain_weight",
  "build_muscle",
  "improve_nutrition",
]);

/**
 * Number-input fields arrive as strings, so an empty field is checked before
 * coercion — otherwise "" coerces to 0 and reports a confusing range error.
 */
function targetField(min: number, max: number, minMessage: string) {
  return z
    .string()
    .trim()
    .min(1, "This field is required.")
    .refine((value) => /^\d+$/.test(value), "Enter a whole number.")
    .transform(Number)
    .pipe(
      z
        .number()
        .min(min, minMessage)
        .max(max, "That is above the supported maximum."),
    );
}

export const nutritionTargetsSchema = z.object({
  calorie_target: targetField(800, 10000, "Aim for at least 800 kcal."),
  protein_target: targetField(0, 1000, "Cannot be negative."),
  carb_target: targetField(0, 1500, "Cannot be negative."),
  fat_target: targetField(0, 800, "Cannot be negative."),
});

export type NutritionTargetsValues = z.infer<typeof nutritionTargetsSchema>;

export const nutritionGoalSchema = nutritionTargetsSchema.extend({
  goal_type: goalTypeSchema,
});

export type NutritionGoalValues = z.infer<typeof nutritionGoalSchema>;