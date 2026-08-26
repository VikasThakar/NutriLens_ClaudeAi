import { AlertCircle } from "lucide-react";

import { cn } from "@/lib/utils";

/** Inline field-level error, rendered under an input. */
export function FieldError({ message }: { message?: string }) {
  if (!message) return null;

  return (
    <p className="flex items-start gap-1.5 text-[0.8125rem] text-destructive">
      <AlertCircle className="mt-px size-3.5 shrink-0" />
      <span>{message}</span>
    </p>
  );
}

/** Form-level error banner, for things the server rejected as a whole. */
export function FormError({
  message,
  className,
}: {
  message?: string | null;
  className?: string;
}) {
  if (!message) return null;

  return (
    <div
      role="alert"
      className={cn(
        "flex items-start gap-2.5 rounded-lg border border-destructive/25 bg-destructive/8 px-3.5 py-3 text-sm text-destructive dark:bg-destructive/12",
        className,
      )}
    >
      <AlertCircle className="mt-px size-4 shrink-0" />
      <span className="leading-relaxed">{message}</span>
    </div>
  );
}
