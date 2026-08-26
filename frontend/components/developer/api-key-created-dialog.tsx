"use client";

import * as React from "react";
import { Check, Copy, KeyRound, TriangleAlert } from "lucide-react";
import { toast } from "sonner";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogTitle,
} from "@/components/ui/dialog";

/**
 * The one and only time a key is shown.
 *
 * Deliberately awkward to dismiss by accident: no click-outside close, an
 * explicit confirmation, and the warning sits above the key rather than below
 * it. The server keeps only a hash, so a user who closes this without copying
 * genuinely cannot recover the key — the UI has to make that consequence
 * obvious before it happens, not after.
 */
export function ApiKeyCreatedDialog({
  plainTextKey,
  keyName,
  onClose,
}: {
  plainTextKey: string | null;
  keyName: string | null;
  onClose: () => void;
}) {
  const [copied, setCopied] = React.useState(false);
  const [acknowledged, setAcknowledged] = React.useState(false);

  // Reset when a new key is shown, so the previous key's state cannot leak in.
  const [shownKey, setShownKey] = React.useState(plainTextKey);
  if (plainTextKey !== shownKey) {
    setShownKey(plainTextKey);
    setCopied(false);
    setAcknowledged(false);
  }

  const copy = async () => {
    if (!plainTextKey) return;

    try {
      await navigator.clipboard.writeText(plainTextKey);
      setCopied(true);
      toast.success("API key copied to your clipboard.");
    } catch {
      // Clipboard access needs a secure context and can be blocked outright.
      toast.error("Could not copy automatically — select the key and copy it manually.");
      setCopied(true);
    }
  };

  return (
    <Dialog
      open={plainTextKey !== null}
      // Outside presses cannot dismiss this, and the onOpenChange guard below
      // ignores Escape too until the key has been acknowledged. Losing this
      // key costs the user real work.
      disablePointerDismissal
      onOpenChange={(open) => {
        if (!open && acknowledged) onClose();
      }}
    >
      <DialogContent showCloseButton={false} className="sm:max-w-lg">
        <div className="flex items-start gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/12 text-primary">
            <KeyRound className="size-[1.125rem]" />
          </span>
          <div className="min-w-0">
            <DialogTitle className="text-base">
              {keyName ? `“${keyName}” is ready` : "Your API key is ready"}
            </DialogTitle>
            <DialogDescription className="mt-1">
              Copy it now and store it somewhere safe.
            </DialogDescription>
          </div>
        </div>

        <div className="flex items-start gap-2.5 rounded-xl bg-carbs/12 p-3.5 ring-1 ring-carbs/25">
          <TriangleAlert className="mt-0.5 size-4 shrink-0 text-carbs" />
          <p className="text-[0.8125rem] leading-relaxed">
            <span className="font-semibold">This is the only time you will see it.</span>{" "}
            NutriLens stores a hash of your key, not the key itself, so it cannot
            be shown again. If you lose it, revoke it and create a new one.
          </p>
        </div>

        <div>
          <label
            htmlFor="new-api-key"
            className="text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase"
          >
            Your API key
          </label>
          <div className="mt-2 flex flex-col gap-2 sm:flex-row">
            <input
              id="new-api-key"
              readOnly
              value={plainTextKey ?? ""}
              onFocus={(event) => event.currentTarget.select()}
              className={cn(
                "min-w-0 flex-1 rounded-lg bg-muted px-3 py-2.5 font-mono text-[0.75rem] break-all",
                "ring-1 ring-border outline-none focus-visible:ring-3 focus-visible:ring-ring/50 sm:text-[0.8125rem]",
              )}
            />
            <Button onClick={() => void copy()} className="shrink-0">
              {copied ? <Check /> : <Copy />}
              {copied ? "Copied" : "Copy"}
            </Button>
          </div>
        </div>

        <label className="flex cursor-pointer items-start gap-2.5 text-[0.8125rem]">
          <input
            type="checkbox"
            checked={acknowledged}
            onChange={(event) => setAcknowledged(event.target.checked)}
            className="mt-0.5 size-4 shrink-0 accent-[var(--primary)]"
          />
          <span className="text-muted-foreground">
            I have copied my API key and understand it will not be shown again.
          </span>
        </label>

        <Button
          size="lg"
          disabled={!acknowledged}
          onClick={onClose}
          className="w-full"
        >
          Done
        </Button>
      </DialogContent>
    </Dialog>
  );
}
