"use client";

import * as React from "react";
import { ArrowUp, Loader2 } from "lucide-react";

import { cn } from "@/lib/utils";
import { COACH_MESSAGE_MAX_LENGTH } from "@/lib/coach";
import { Button } from "@/components/ui/button";

/** Beyond this the textarea scrolls instead of growing further. */
const MAX_HEIGHT_PX = 160;

/** The counter appears only once the limit is genuinely in sight. */
const COUNTER_FROM = COACH_MESSAGE_MAX_LENGTH - 200;

/**
 * The message input.
 *
 * Sticky rather than fixed, and inside the page flow: when a mobile keyboard
 * opens, the browser scrolls the focused element into view by itself, which is
 * far more reliable across iOS and Android than trying to position a fixed bar
 * against a viewport that is changing size underneath you. On mobile it sits
 * above the bottom navigation and its safe area; on desktop, where there is no
 * bottom bar, it sits just above the edge.
 */
export function CoachComposer({
  onSubmit,
  sending,
  placeholder = "Ask about your meals, goals, or progress…",
}: {
  onSubmit: (message: string) => void;
  sending: boolean;
  placeholder?: string;
}) {
  const [value, setValue] = React.useState("");
  const textareaRef = React.useRef<HTMLTextAreaElement>(null);

  // Grow with the content, up to a ceiling. Runs after every change so
  // deleting lines shrinks it again.
  React.useEffect(() => {
    const element = textareaRef.current;
    if (!element) return;

    element.style.height = "auto";
    element.style.height = `${Math.min(element.scrollHeight, MAX_HEIGHT_PX)}px`;
  }, [value]);

  const trimmed = value.trim();
  const canSend = trimmed.length > 0 && !sending;

  const submit = () => {
    if (!canSend) return;

    onSubmit(trimmed);
    setValue("");
    // Keep focus so a follow-up question needs no extra tap.
    textareaRef.current?.focus();
  };

  const handleKeyDown = (event: React.KeyboardEvent<HTMLTextAreaElement>) => {
    // Shift+Enter is a new line. An IME composition must never be cut short by
    // Enter, or typing in Japanese or Chinese becomes impossible.
    if (
      event.key === "Enter" &&
      !event.shiftKey &&
      !event.nativeEvent.isComposing
    ) {
      event.preventDefault();
      submit();
    }
  };

  return (
    /*
      The bottom offsets clear the mobile navigation bar (4rem) and its safe
      area; on desktop, where there is no bottom bar, a small gap is enough.
      They are paired with the negative bottom margin on the coach screen: a
      sticky element cannot leave its containing block, so without that the
      composer would drift upward as the page reached its end.
    */
    <div className="sticky bottom-[calc(4rem+env(safe-area-inset-bottom,0px))] z-20 -mx-1 px-1 pt-3 pb-1 lg:bottom-5">
      <form
        onSubmit={(event) => {
          event.preventDefault();
          submit();
        }}
        className={cn(
          "flex items-end gap-2 rounded-2xl border border-border bg-card/95 p-2 backdrop-blur-md elevate",
          "focus-within:border-ring/60 focus-within:ring-3 focus-within:ring-ring/25",
        )}
      >
        <label htmlFor="coach-message" className="sr-only">
          Message your nutrition coach
        </label>

        <textarea
          id="coach-message"
          ref={textareaRef}
          rows={1}
          value={value}
          onChange={(event) => setValue(event.target.value)}
          onKeyDown={handleKeyDown}
          maxLength={COACH_MESSAGE_MAX_LENGTH}
          placeholder={placeholder}
          enterKeyHint="send"
          autoComplete="off"
          className={cn(
            // text-base on mobile: anything smaller makes iOS Safari zoom the
            // whole page when the field is focused.
            "max-h-40 min-h-10 w-full flex-1 resize-none bg-transparent px-2 py-2 text-base leading-relaxed",
            "placeholder:text-muted-foreground focus:outline-none sm:text-[0.9375rem]",
          )}
        />

        <Button
          type="submit"
          size="icon-lg"
          disabled={!canSend}
          aria-label={sending ? "Sending" : "Send message"}
          className="shrink-0 rounded-xl"
        >
          {sending ? <Loader2 className="animate-spin" /> : <ArrowUp />}
        </Button>
      </form>

      <div className="mt-1.5 flex items-center justify-between gap-3 px-1">
        <p className="hidden text-[0.6875rem] text-muted-foreground sm:block">
          Enter to send · Shift + Enter for a new line
        </p>
        {value.length >= COUNTER_FROM ? (
          <p
            className={cn(
              "ml-auto text-[0.6875rem] tabular-nums",
              value.length >= COACH_MESSAGE_MAX_LENGTH
                ? "font-medium text-destructive"
                : "text-muted-foreground",
            )}
          >
            {value.length} / {COACH_MESSAGE_MAX_LENGTH}
          </p>
        ) : (
          <p className="ml-auto text-[0.6875rem] text-muted-foreground">
            Answers use your logged data
          </p>
        )}
      </div>
    </div>
  );
}
