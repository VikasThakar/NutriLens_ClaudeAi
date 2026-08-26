"use client";

import * as React from "react";
import Link from "next/link";
import {
  AlertCircle,
  Camera,
  History,
  MessageSquarePlus,
  RefreshCw,
} from "lucide-react";
import { toast } from "sonner";

import { ApiError } from "@/lib/api-client";
import { coachService } from "@/services";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetTitle,
} from "@/components/ui/sheet";
import { PageHeader } from "@/components/shared/page-header";
import { LogoMark } from "@/components/layout/logo";
import { CoachComposer } from "@/components/coach/coach-composer";
import { CoachConversationList } from "@/components/coach/coach-conversations";
import {
  CoachErrorRow,
  CoachMessageRow,
  UserBubble,
} from "@/components/coach/coach-message";
import {
  CoachProgress,
  CoachProgressSkeleton,
} from "@/components/coach/coach-progress";
import {
  CoachQuickActionCards,
  CoachQuickActionChips,
} from "@/components/coach/coach-quick-actions";
import { CoachThinking } from "@/components/coach/coach-thinking";
import type {
  CoachContext,
  CoachConversation,
  CoachMessage,
} from "@/types/api";

interface FailedSend {
  text: string;
  error: string;
  retryable: boolean;
}

const GENERIC_SEND_ERROR = "Your coach could not answer that. Please try again.";

/**
 * Laravel's own 429 body says "Too Many Attempts", which is true but tells the
 * user nothing about what to do. Every other status already carries a message
 * written for this feature, so only this one needs replacing.
 */
const RATE_LIMITED_ERROR =
  "You have reached the AI Coach limit for now. Wait a minute and ask again — "
  + "your meals, goals and progress are all unaffected.";

export function CoachScreen() {
  const [context, setContext] = React.useState<CoachContext | null>(null);
  const [contextError, setContextError] = React.useState<string | null>(null);
  const [loadingContext, setLoadingContext] = React.useState(true);

  const [conversations, setConversations] = React.useState<CoachConversation[]>([]);
  const [loadingConversations, setLoadingConversations] = React.useState(true);
  const [deletingId, setDeletingId] = React.useState<number | null>(null);

  /** Null while a new, not-yet-created chat is open. */
  const [activeId, setActiveId] = React.useState<number | null>(null);
  const [messages, setMessages] = React.useState<CoachMessage[]>([]);
  const [loadingThread, setLoadingThread] = React.useState(false);
  const [threadError, setThreadError] = React.useState<string | null>(null);

  /** The question currently in flight, shown optimistically. */
  const [pending, setPending] = React.useState<string | null>(null);
  const [failed, setFailed] = React.useState<FailedSend | null>(null);

  const [historyOpen, setHistoryOpen] = React.useState(false);
  const [reloadKey, setReloadKey] = React.useState(0);

  const sending = pending !== null;

  /* ---------------------------------------------------------------- */
  /* Loading                                                           */
  /* ---------------------------------------------------------------- */

  React.useEffect(() => {
    let cancelled = false;

    // The two loads are independent on purpose: a failure to build the
    // nutrition context should not take the chat down with it, and vice versa.
    void (async () => {
      try {
        const { data } = await coachService.context();
        if (cancelled) return;
        setContext(data);
        setContextError(null);
      } catch (caught) {
        if (cancelled) return;
        setContextError(
          caught instanceof ApiError
            ? caught.message
            : "Could not load today's figures.",
        );
      } finally {
        if (!cancelled) setLoadingContext(false);
      }
    })();

    void (async () => {
      try {
        const { data } = await coachService.conversations();
        if (cancelled) return;
        setConversations(data);

        // Reopen the most recent thread, so a page refresh lands the user back
        // where they were rather than on a blank chat.
        const latest = data.find((conversation) => conversation.message_count > 0);

        if (latest) {
          setActiveId(latest.id);
          const full = await coachService.conversation(latest.id);
          if (cancelled) return;
          setMessages(full.data.messages ?? []);
        }
      } catch (caught) {
        if (cancelled) return;
        setThreadError(
          caught instanceof ApiError
            ? caught.message
            : "Could not load your chats.",
        );
      } finally {
        if (!cancelled) setLoadingConversations(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [reloadKey]);

  const retryLoad = () => {
    setLoadingContext(true);
    setLoadingConversations(true);
    setContextError(null);
    setThreadError(null);
    setMessages([]);
    setActiveId(null);
    setReloadKey((key) => key + 1);
  };

  /* ---------------------------------------------------------------- */
  /* Auto-scroll                                                       */
  /* ---------------------------------------------------------------- */

  const endRef = React.useRef<HTMLDivElement>(null);

  /**
   * Scrolling is driven by an explicit counter rather than by `messages`
   * changing.
   *
   * The difference matters on arrival: restoring the last thread would
   * otherwise scroll the page straight past the header and the progress card,
   * which is the first thing someone landing here should see. Bumping the
   * counter only in response to something the user did — sending, retrying,
   * or opening a thread from the list — keeps the page still until then.
   */
  const [scrollTick, setScrollTick] = React.useState(0);
  const followConversation = () => setScrollTick((tick) => tick + 1);

  React.useEffect(() => {
    if (scrollTick === 0) return;

    endRef.current?.scrollIntoView({ behavior: "smooth", block: "end" });
  }, [scrollTick]);

  /* ---------------------------------------------------------------- */
  /* Actions                                                           */
  /* ---------------------------------------------------------------- */

  const send = React.useCallback(
    async (text: string) => {
      if (pending !== null) return;

      setFailed(null);
      setThreadError(null);
      setPending(text);
      followConversation();

      try {
        let conversationId = activeId;

        // A thread is created lazily, on its first message — so opening "New
        // chat" and changing your mind leaves nothing behind.
        if (conversationId === null) {
          const created = await coachService.createConversation();
          conversationId = created.data.id;
          setActiveId(conversationId);
          setConversations((current) => [created.data, ...current]);
        }

        const { data } = await coachService.send(conversationId, text);

        setMessages((current) => [...current, data.user_message, data.reply]);

        // The reply carries the context it was written from, so the progress
        // card stays in step without a second request. `is_simulated` only
        // comes from the context endpoint, so it is preserved.
        setContext((current) => ({
          ...data.context,
          is_simulated: current?.is_simulated ?? data.context.is_simulated,
        }));

        setConversations((current) => [
          data.conversation,
          ...current.filter((conversation) => conversation.id !== data.conversation.id),
        ]);
      } catch (caught) {
        const apiError = caught instanceof ApiError ? caught : null;

        setFailed({
          text,
          error:
            apiError?.status === 429
              ? RATE_LIMITED_ERROR
              : (apiError?.message ?? GENERIC_SEND_ERROR),
          retryable: apiError ? apiError.retryable : true,
        });
      } finally {
        setPending(null);
        followConversation();
      }
    },
    [activeId, pending],
  );

  const startNewChat = () => {
    // Same reason as openConversation: an in-flight reply belongs to the thread
    // it was asked in.
    if (sending) return;

    setActiveId(null);
    setMessages([]);
    setFailed(null);
    setThreadError(null);
    setHistoryOpen(false);
  };

  const openConversation = async (conversation: CoachConversation) => {
    setHistoryOpen(false);

    // Switching threads mid-request would land the reply in the wrong one.
    if (sending || conversation.id === activeId) return;

    setActiveId(conversation.id);
    setMessages([]);
    setFailed(null);
    setThreadError(null);
    setLoadingThread(true);

    try {
      const { data } = await coachService.conversation(conversation.id);
      setMessages(data.messages ?? []);
      followConversation();
    } catch (caught) {
      setThreadError(
        caught instanceof ApiError ? caught.message : "Could not open that chat.",
      );
    } finally {
      setLoadingThread(false);
    }
  };

  const deleteConversation = async (conversation: CoachConversation) => {
    setDeletingId(conversation.id);

    try {
      await coachService.deleteConversation(conversation.id);

      setConversations((current) =>
        current.filter((item) => item.id !== conversation.id),
      );

      if (activeId === conversation.id) {
        startNewChat();
      }

      toast.success("Chat deleted.");
    } catch (caught) {
      toast.error(
        caught instanceof ApiError ? caught.message : "Could not delete that chat.",
      );
    } finally {
      setDeletingId(null);
    }
  };

  /* ---------------------------------------------------------------- */
  /* Render                                                            */
  /* ---------------------------------------------------------------- */

  const conversationList = (
    <CoachConversationList
      conversations={conversations}
      activeId={activeId}
      loading={loadingConversations}
      deletingId={deletingId}
      busy={sending}
      onSelect={(conversation) => void openConversation(conversation)}
      onNew={startNewChat}
      onDelete={(conversation) => void deleteConversation(conversation)}
    />
  );

  const threadIsEmpty =
    messages.length === 0 && !sending && failed === null && !loadingThread;

  return (
    /*
      The negative bottom margin trims most of the app shell's bottom padding
      (`pb-28 lg:pb-12`), which exists to keep content clear of the fixed mobile
      nav. This screen does not need it — the composer is the last element and
      is sticky — and leaving it in place would make the composer drift upward
      as the page reached its end, since a sticky element cannot escape its
      containing block. What is left lines up with the composer's own offsets.
    */
    <div className="space-y-5 -mb-24 lg:-mb-8">
      <PageHeader
        eyebrow="AI Coach"
        title="Your AI Nutrition Coach"
        description="Ask questions about your meals, goals, and nutrition progress."
        action={
          <div className="flex gap-2 lg:hidden">
            <Button
              variant="outline"
              onClick={() => setHistoryOpen(true)}
              disabled={loadingConversations}
            >
              <History />
              Chats
              {conversations.length > 0 && (
                <span className="text-muted-foreground">
                  {conversations.length}
                </span>
              )}
            </Button>
            <Button onClick={startNewChat} disabled={sending}>
              <MessageSquarePlus />
              New
            </Button>
          </div>
        }
      />

      {loadingContext ? (
        <CoachProgressSkeleton />
      ) : context ? (
        <CoachProgress context={context} />
      ) : (
        <ContextUnavailable message={contextError} onRetry={retryLoad} />
      )}

      <div className="grid gap-5 lg:grid-cols-[15rem_minmax(0,1fr)]">
        <aside className="hidden lg:block">
          <div className="sticky top-6 flex max-h-[calc(100dvh-4rem)] flex-col rounded-2xl bg-card p-3 ring-1 ring-foreground/10">
            {conversationList}
          </div>
        </aside>

        <div className="min-w-0">
          {loadingThread && <ThreadSkeleton />}

          {threadError && (
            <div
              role="alert"
              className="mb-4 flex flex-col gap-3 rounded-2xl bg-card p-4 ring-1 ring-destructive/25 sm:flex-row sm:items-center sm:justify-between"
            >
              <div className="flex items-start gap-3">
                <AlertCircle className="mt-0.5 size-5 shrink-0 text-destructive" />
                <p className="text-sm">{threadError}</p>
              </div>
              <Button variant="outline" size="sm" onClick={retryLoad}>
                <RefreshCw />
                Reload
              </Button>
            </div>
          )}

          {threadIsEmpty && !loadingThread && (
            <EmptyConversation
              context={context}
              onSelect={(prompt) => void send(prompt)}
              disabled={sending}
            />
          )}

          {!threadIsEmpty && !loadingThread && (
            <section aria-label="Conversation with your coach" className="space-y-4">
              {messages.map((message, index) => (
                <CoachMessageRow
                  key={message.id}
                  message={message}
                  isLatestReply={
                    message.role === "assistant" && index === messages.length - 1
                  }
                  onSuggestion={(prompt) => void send(prompt)}
                  suggestionsDisabled={sending}
                />
              ))}

              {pending !== null && (
                <>
                  <UserBubble text={pending} />
                  <CoachThinking />
                </>
              )}

              {failed && (
                <>
                  <UserBubble text={failed.text} muted />
                  <CoachErrorRow
                    message={failed.error}
                    retryable={failed.retryable}
                    onRetry={() => void send(failed.text)}
                    onNewChat={startNewChat}
                  />
                </>
              )}
            </section>
          )}

          {/* Scroll anchor. The margin keeps the sticky composer from covering
              the newest message when the thread is scrolled into view. */}
          <div ref={endRef} aria-hidden="true" className="h-px scroll-mb-52" />

          {messages.length > 0 && (
            <CoachQuickActionChips
              className="mt-4"
              onSelect={(prompt) => void send(prompt)}
              disabled={sending}
            />
          )}

          <CoachComposer onSubmit={(text) => void send(text)} sending={sending} />
        </div>
      </div>

      {/* Mobile chat history */}
      <Sheet open={historyOpen} onOpenChange={setHistoryOpen}>
        <SheetContent
          side="bottom"
          className="max-h-[82dvh] rounded-t-2xl p-0 sm:mx-auto sm:max-w-md lg:hidden"
        >
          <div className="flex max-h-[82dvh] flex-col p-4 pb-safe">
            <SheetTitle className="text-base">Your chats</SheetTitle>
            <SheetDescription className="mt-0.5">
              Pick up where you left off, or start something new.
            </SheetDescription>
            <div className="mt-4 min-h-0 flex-1 overflow-hidden">
              {conversationList}
            </div>
          </div>
        </SheetContent>
      </Sheet>
    </div>
  );
}

/* -------------------------------------------------------------------------- */

function EmptyConversation({
  context,
  onSelect,
  disabled,
}: {
  context: CoachContext | null;
  onSelect: (prompt: string) => void;
  disabled: boolean;
}) {
  const noData = context !== null && !context.has_any_meals;

  return (
    <section className="space-y-4">
      <div className="rounded-2xl bg-card p-5 ring-1 ring-foreground/10 sm:p-6">
        <span className="flex size-11 items-center justify-center rounded-xl bg-primary/12 text-primary">
          <LogoMark className="size-6" />
        </span>

        <h2 className="mt-3.5 font-heading text-lg font-semibold">
          Ask questions. Get answers based on your actual nutrition data.
        </h2>

        <p className="mt-1.5 max-w-xl text-sm leading-relaxed text-muted-foreground">
          {noData
            ? "There is not much logged yet, so I cannot tell you how a meal fits your day. I can still help you set targets and suggest general meal ideas — and once you log a meal, the answers get specific."
            : "Your coach reads today's totals, what is left of your targets, your recent meals and your last seven days before it answers. It will not invent a figure, and it will tell you when it does not know."}
        </p>

        {noData && (
          <Button
            render={<Link href="/add-meal" />}
            variant="outline"
            size="sm"
            className="mt-4"
          >
            <Camera />
            Log your first meal
          </Button>
        )}
      </div>

      <div>
        <h3 className="mb-2.5 px-1 text-[0.6875rem] font-semibold tracking-wide text-muted-foreground uppercase">
          Try one of these
        </h3>
        <CoachQuickActionCards onSelect={onSelect} disabled={disabled} />
      </div>
    </section>
  );
}

function ContextUnavailable({
  message,
  onRetry,
}: {
  message: string | null;
  onRetry: () => void;
}) {
  return (
    <div
      role="alert"
      className="flex flex-col gap-3 rounded-2xl bg-card p-4 ring-1 ring-destructive/25 sm:flex-row sm:items-center sm:justify-between sm:p-5"
    >
      <div className="flex items-start gap-3">
        <AlertCircle className="mt-0.5 size-5 shrink-0 text-destructive" />
        <div>
          <p className="text-sm font-semibold">
            Could not load today&apos;s figures
          </p>
          <p className="mt-1 text-sm text-muted-foreground">
            {message ??
              "Your coach can still answer, but it will not have today's progress in front of it."}
          </p>
        </div>
      </div>
      <Button variant="outline" size="sm" onClick={onRetry}>
        <RefreshCw />
        Try again
      </Button>
    </div>
  );
}

function ThreadSkeleton() {
  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <Skeleton className="h-10 w-48 rounded-2xl" />
      </div>
      <div className="flex gap-2.5">
        <Skeleton className="size-7 shrink-0 rounded-lg" />
        <Skeleton className="h-24 flex-1 rounded-2xl" />
      </div>
      <div className="flex justify-end">
        <Skeleton className="h-10 w-36 rounded-2xl" />
      </div>
    </div>
  );
}
