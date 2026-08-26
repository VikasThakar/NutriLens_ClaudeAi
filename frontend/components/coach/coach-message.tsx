"use client";

import * as React from "react";
import { AlertCircle, RefreshCw } from "lucide-react";

import { cn } from "@/lib/utils";
import { toCoachBlocks } from "@/lib/coach";
import { Button } from "@/components/ui/button";
import { LogoMark } from "@/components/layout/logo";
import { CoachSuggestions } from "@/components/coach/coach-quick-actions";
import type { CoachMessage as CoachMessageData } from "@/types/api";

/**
 * The NutriLens mark, small, as the coach's avatar. Using the product's own
 * glyph rather than a generic robot is most of what keeps this screen from
 * looking like every other chat window.
 */
function CoachAvatar() {
  return (
    <span
      aria-hidden="true"
      className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-primary/12 text-primary"
    >
      <LogoMark className="size-[1.125rem]" />
    </span>
  );
}

function Bubble({
  children,
  className,
}: {
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <div
      className={cn(
        // max-w rather than a fixed width: a one-line answer should not be
        // stretched to fill a desktop column.
        "max-w-[min(100%,44rem)] rounded-2xl px-3.5 py-2.5 text-[0.9375rem] leading-relaxed",
        className,
      )}
    >
      {children}
    </div>
  );
}

/** Plain text with paragraphs and the occasional short list. No markdown. */
function CoachBody({ content }: { content: string }) {
  const blocks = React.useMemo(() => toCoachBlocks(content), [content]);

  return (
    <div className="space-y-2.5">
      {blocks.map((block, index) =>
        block.kind === "list" ? (
          <ul key={index} className="space-y-1.5 pl-1">
            {block.items.map((item, itemIndex) => (
              <li key={itemIndex} className="flex gap-2">
                <span
                  aria-hidden="true"
                  className="mt-[0.5rem] size-1.5 shrink-0 rounded-full bg-primary/70"
                />
                <span className="min-w-0">{item}</span>
              </li>
            ))}
          </ul>
        ) : (
          <p key={index} className="whitespace-pre-line">
            {block.text}
          </p>
        ),
      )}
    </div>
  );
}

export function CoachMessageRow({
  message,
  isLatestReply,
  onSuggestion,
  suggestionsDisabled,
}: {
  message: CoachMessageData;
  isLatestReply?: boolean;
  onSuggestion?: (prompt: string) => void;
  suggestionsDisabled?: boolean;
}) {
  if (message.role === "user") {
    return <UserBubble text={message.content} />;
  }

  return (
    <div className="flex gap-2.5">
      <CoachAvatar />

      <div className="min-w-0 flex-1">
        <Bubble className="bg-card text-card-foreground ring-1 ring-foreground/10">
          <CoachBody content={message.content} />

          {message.is_simulated && (
            <p className="mt-2.5 border-t border-border pt-2 text-[0.6875rem] leading-snug text-muted-foreground">
              Simulated reply — this server has no AI key configured, so
              NutriLens composed this from your own logged data.
            </p>
          )}
        </Bubble>

        {/*
          Follow-ups are offered only under the newest reply: keeping them on
          every historical message would turn the thread into a wall of chips.
        */}
        {isLatestReply && onSuggestion && (
          <CoachSuggestions
            suggestions={message.suggestions}
            onSelect={onSuggestion}
            disabled={suggestionsDisabled}
          />
        )}
      </div>
    </div>
  );
}

export function UserBubble({
  text,
  muted,
}: {
  text: string;
  muted?: boolean;
}) {
  return (
    <div className="flex justify-end">
      <Bubble
        className={cn(
          "rounded-br-md whitespace-pre-line",
          muted
            ? "bg-muted text-muted-foreground ring-1 ring-foreground/10"
            : "bg-primary text-primary-foreground",
        )}
      >
        {text}
      </Bubble>
    </div>
  );
}

/**
 * A question that never reached the coach.
 *
 * The backend stores the question and the answer in one transaction, so a
 * failure leaves nothing behind — which is what makes "Try again" safe rather
 * than a way to duplicate the message.
 */
export function CoachErrorRow({
  message,
  retryable,
  onRetry,
  onNewChat,
}: {
  message: string;
  retryable: boolean;
  onRetry: () => void;
  onNewChat: () => void;
}) {
  return (
    <div className="flex gap-2.5" role="alert">
      <span
        aria-hidden="true"
        className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-destructive/12 text-destructive"
      >
        <AlertCircle className="size-4" />
      </span>

      <div className="min-w-0 flex-1 rounded-2xl bg-card p-3.5 ring-1 ring-destructive/25">
        <p className="text-[0.9375rem] leading-relaxed">{message}</p>

        <div className="mt-3 flex flex-wrap gap-2">
          {retryable && (
            <Button size="sm" variant="outline" onClick={onRetry}>
              <RefreshCw />
              Try again
            </Button>
          )}
          <Button size="sm" variant="ghost" onClick={onNewChat}>
            Start a new chat
          </Button>
        </div>
      </div>
    </div>
  );
}
