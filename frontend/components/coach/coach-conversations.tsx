"use client";

import { Loader2, MessageSquarePlus, Trash2 } from "lucide-react";

import { cn } from "@/lib/utils";
import { formatDayLabel, toISODate } from "@/lib/dates";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import type { CoachConversation } from "@/types/api";

function whenLabel(conversation: CoachConversation): string {
  const iso = conversation.last_message_at ?? conversation.created_at;
  if (!iso) return "";

  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "";

  return formatDayLabel(toISODate(date));
}

/**
 * The list of past chats.
 *
 * Rendered in a desktop column and inside a mobile sheet — one component, two
 * placements, so the two can never drift apart.
 */
export function CoachConversationList({
  conversations,
  activeId,
  loading,
  deletingId,
  busy,
  onSelect,
  onNew,
  onDelete,
  className,
}: {
  conversations: CoachConversation[];
  /** Null while a new, not-yet-created chat is open. */
  activeId: number | null;
  loading?: boolean;
  deletingId?: number | null;
  /** A reply is in flight, so switching threads would misplace it. */
  busy?: boolean;
  onSelect: (conversation: CoachConversation) => void;
  onNew: () => void;
  onDelete: (conversation: CoachConversation) => void;
  className?: string;
}) {
  return (
    <div className={cn("flex min-h-0 flex-col gap-3", className)}>
      <Button
        variant="outline"
        className="w-full justify-start"
        disabled={busy}
        onClick={onNew}
      >
        <MessageSquarePlus />
        New chat
      </Button>

      <div className="min-h-0 flex-1 overflow-y-auto">
        {loading ? (
          <div className="space-y-2">
            <Skeleton className="h-14 rounded-xl" />
            <Skeleton className="h-14 rounded-xl" />
            <Skeleton className="h-14 rounded-xl" />
          </div>
        ) : conversations.length === 0 ? (
          <p className="px-1 py-2 text-[0.8125rem] leading-relaxed text-muted-foreground">
            Your chats will appear here once you have asked something.
          </p>
        ) : (
          <ul className="space-y-1.5">
            {conversations.map((conversation) => {
              const active = conversation.id === activeId;
              const deleting = deletingId === conversation.id;

              return (
                <li key={conversation.id} className="group/row relative">
                  <button
                    type="button"
                    onClick={() => onSelect(conversation)}
                    disabled={busy && !active}
                    aria-current={active ? "true" : undefined}
                    className={cn(
                      "w-full rounded-xl px-3 py-2.5 pr-10 text-left transition-colors",
                      "focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
                      "disabled:pointer-events-none disabled:opacity-50",
                      active
                        ? "bg-accent ring-1 ring-primary/25"
                        : "hover:bg-accent/60",
                    )}
                  >
                    <span
                      className={cn(
                        "block truncate text-[0.8125rem] font-medium",
                        active && "text-accent-foreground",
                      )}
                    >
                      {conversation.title ?? "New chat"}
                    </span>
                    <span className="mt-0.5 block text-[0.6875rem] text-muted-foreground">
                      {whenLabel(conversation)}
                      {conversation.message_count > 0 && (
                        <>
                          {" · "}
                          {Math.ceil(conversation.message_count / 2)} question
                          {Math.ceil(conversation.message_count / 2) === 1 ? "" : "s"}
                        </>
                      )}
                    </span>
                  </button>

                  <Button
                    variant="ghost"
                    size="icon-xs"
                    aria-label={`Delete chat: ${conversation.title ?? "New chat"}`}
                    disabled={deleting || busy}
                    onClick={() => onDelete(conversation)}
                    className={cn(
                      "absolute top-2.5 right-2 text-muted-foreground hover:text-destructive",
                      // Always reachable on touch; revealed on hover/focus on
                      // a pointer device, where a permanent icon is noise.
                      "lg:opacity-0 lg:group-hover/row:opacity-100 lg:focus-visible:opacity-100",
                    )}
                  >
                    {deleting ? (
                      <Loader2 className="animate-spin" />
                    ) : (
                      <Trash2 />
                    )}
                  </Button>
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </div>
  );
}
