"use client";

import * as React from "react";
import {
  AlertCircle,
  BookOpen,
  ExternalLink,
  KeyRound,
  Loader2,
  Plus,
  RefreshCw,
  Trash2,
} from "lucide-react";
import { toast } from "sonner";

import { cn } from "@/lib/utils";
import { ApiError } from "@/lib/api-client";
import { apiDocsUrl } from "@/lib/env";
import { apiKeysService } from "@/services";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { FieldError, FormError } from "@/components/shared/form-message";
import { PageHeader } from "@/components/shared/page-header";
import { ApiKeyCreatedDialog } from "@/components/developer/api-key-created-dialog";
import type { ApiKey } from "@/types/api";

function formatDateTime(iso: string | null): string {
  if (!iso) return "—";

  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "—";

  return date.toLocaleString("en-US", { dateStyle: "medium", timeStyle: "short" });
}

export function ApiKeysScreen() {
  const [keys, setKeys] = React.useState<ApiKey[]>([]);
  const [meta, setMeta] = React.useState({ active_count: 0, max_active: 10 });
  const [loading, setLoading] = React.useState(true);
  const [loadError, setLoadError] = React.useState<string | null>(null);
  const [reloadKey, setReloadKey] = React.useState(0);

  const [name, setName] = React.useState("");
  const [nameError, setNameError] = React.useState<string | undefined>();
  const [formError, setFormError] = React.useState<string | null>(null);
  const [creating, setCreating] = React.useState(false);
  const [revokingId, setRevokingId] = React.useState<number | null>(null);

  /** Held in component state only, and cleared when the dialog closes. */
  const [newKey, setNewKey] = React.useState<{ plain: string; name: string } | null>(
    null,
  );

  // Every setState sits after an await, so the effect body never triggers a
  // synchronous re-render.
  React.useEffect(() => {
    let cancelled = false;

    void (async () => {
      try {
        const response = await apiKeysService.list();
        if (cancelled) return;
        setKeys(response.data);
        setMeta(response.meta);
        setLoadError(null);
      } catch (caught) {
        if (cancelled) return;
        setLoadError(
          caught instanceof ApiError
            ? caught.message
            : "Could not load your API keys.",
        );
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [reloadKey]);

  const reload = () => setReloadKey((current) => current + 1);

  const retry = () => {
    setLoading(true);
    setLoadError(null);
    reload();
  };

  const create = async () => {
    setFormError(null);
    setNameError(undefined);

    const trimmed = name.trim();

    if (trimmed.length < 2) {
      setNameError("Give the key a name so you can recognise it later.");
      return;
    }

    setCreating(true);

    try {
      const response = await apiKeysService.create(trimmed);
      setNewKey({
        plain: response.data.plain_text_key,
        name: response.data.key.name,
      });
      setName("");
      reload();
    } catch (caught) {
      if (caught instanceof ApiError && caught.isValidation) {
        const message = caught.fieldError("name");
        if (message) setNameError(message);
        else setFormError(caught.message);
      } else {
        setFormError(
          caught instanceof ApiError ? caught.message : "Could not create the key.",
        );
      }
    } finally {
      setCreating(false);
    }
  };

  const revoke = async (key: ApiKey) => {
    setRevokingId(key.id);

    try {
      await apiKeysService.revoke(key.id);
      toast.success(`${key.name} revoked.`);
      reload();
    } catch (caught) {
      toast.error(
        caught instanceof ApiError ? caught.message : "Could not revoke that key.",
      );
    } finally {
      setRevokingId(null);
    }
  };

  const atLimit = meta.active_count >= meta.max_active;

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Developer"
        title="API keys"
        description="Keys for the NutriLens Partner API — send a meal photo or a list of foods, get nutrition back."
        action={
          <Button
            render={<a href={apiDocsUrl} target="_blank" rel="noreferrer noopener" />}
            variant="outline"
            size="lg"
          >
            <BookOpen />
            API reference
            <ExternalLink className="size-3.5" />
          </Button>
        }
      />

      <FormError message={formError} />

      {/* Create */}
      <section className="rounded-2xl bg-card p-5 ring-1 ring-foreground/10 sm:p-6">
        <h2 className="font-heading text-[0.9375rem] font-semibold">Create a key</h2>
        <p className="mt-1 max-w-2xl text-sm leading-relaxed text-muted-foreground">
          Name it after wherever you plan to use it — the name is the only way to
          tell two keys apart later. The key itself is shown once, immediately
          after it is created, and is stored here only as a hash.
        </p>

        <div className="mt-5 flex flex-col gap-3 sm:flex-row sm:items-start">
          <div className="min-w-0 flex-1 space-y-2">
            <Label htmlFor="api-key-name">Key name</Label>
            <Input
              id="api-key-name"
              value={name}
              maxLength={60}
              placeholder="Acme staging server"
              aria-invalid={Boolean(nameError)}
              disabled={atLimit}
              onChange={(event) => setName(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === "Enter" && !creating && !atLimit) void create();
              }}
            />
            <FieldError message={nameError} />
          </div>

          <Button
            size="lg"
            className="sm:mt-8"
            disabled={creating || atLimit}
            onClick={() => void create()}
          >
            {creating ? (
              <>
                <Loader2 className="animate-spin" />
                Creating…
              </>
            ) : (
              <>
                <Plus />
                Create key
              </>
            )}
          </Button>
        </div>

        {atLimit && (
          <p className="mt-3 text-[0.8125rem] text-muted-foreground">
            You have {meta.active_count} of {meta.max_active} active keys. Revoke
            one before creating another.
          </p>
        )}
      </section>

      {/* List */}
      {loading && (
        <div className="space-y-2.5">
          <Skeleton className="h-28 rounded-2xl" />
          <Skeleton className="h-28 rounded-2xl" />
        </div>
      )}

      {!loading && loadError && (
        <div
          role="alert"
          className="flex flex-col gap-4 rounded-2xl bg-card p-6 ring-1 ring-destructive/25 sm:flex-row sm:items-center sm:justify-between"
        >
          <div className="flex items-start gap-3">
            <AlertCircle className="mt-0.5 size-5 shrink-0 text-destructive" />
            <div>
              <p className="text-sm font-semibold">Could not load your API keys</p>
              <p className="mt-1 text-sm text-muted-foreground">{loadError}</p>
            </div>
          </div>
          <Button variant="outline" onClick={retry}>
            <RefreshCw />
            Try again
          </Button>
        </div>
      )}

      {!loading && !loadError && (
        <section>
          <h2 className="mb-2.5 px-1 font-heading text-[0.9375rem] font-semibold">
            Your keys
            {keys.length > 0 && (
              <span className="ml-2 text-[0.8125rem] font-normal text-muted-foreground tabular-nums">
                {meta.active_count} active
              </span>
            )}
          </h2>

          {keys.length === 0 ? (
            <EmptyKeys />
          ) : (
            <ul className="space-y-2.5">
              {keys.map((key) => (
                <KeyRow
                  key={key.id}
                  apiKey={key}
                  revoking={revokingId === key.id}
                  onRevoke={() => void revoke(key)}
                />
              ))}
            </ul>
          )}
        </section>
      )}

      <ApiKeyCreatedDialog
        plainTextKey={newKey?.plain ?? null}
        keyName={newKey?.name ?? null}
        onClose={() => setNewKey(null)}
      />
    </div>
  );
}

function KeyRow({
  apiKey,
  revoking,
  onRevoke,
}: {
  apiKey: ApiKey;
  revoking: boolean;
  onRevoke: () => void;
}) {
  const [confirming, setConfirming] = React.useState(false);

  return (
    <li
      className={cn(
        "rounded-2xl bg-card p-4 ring-1 ring-foreground/10 transition-opacity sm:p-5",
        revoking && "opacity-50",
        !apiKey.is_active && "bg-muted/40",
      )}
    >
      <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
        <div className="flex min-w-0 items-start gap-3">
          <span
            className={cn(
              "flex size-9 shrink-0 items-center justify-center rounded-lg",
              apiKey.is_active
                ? "bg-primary/12 text-primary"
                : "bg-muted text-muted-foreground",
            )}
          >
            <KeyRound className="size-4" />
          </span>

          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <p className="truncate text-sm font-semibold">{apiKey.name}</p>
              {!apiKey.is_active && (
                <span className="rounded-full bg-muted px-2 py-0.5 text-[0.625rem] font-semibold tracking-wide text-muted-foreground uppercase">
                  Revoked
                </span>
              )}
            </div>

            <p className="mt-1 font-mono text-[0.75rem] break-all text-muted-foreground">
              {apiKey.key_prefix}
              <span aria-hidden="true">••••••••••••••••••••••••••••</span>
            </p>

            <dl className="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-[0.6875rem] text-muted-foreground">
              <div className="flex gap-1.5">
                <dt>Created</dt>
                <dd className="font-medium text-foreground/80">
                  {formatDateTime(apiKey.created_at)}
                </dd>
              </div>
              <div className="flex gap-1.5">
                <dt>Last used</dt>
                <dd className="font-medium text-foreground/80">
                  {apiKey.last_used_at ? formatDateTime(apiKey.last_used_at) : "Never"}
                </dd>
              </div>
              {apiKey.revoked_at && (
                <div className="flex gap-1.5">
                  <dt>Revoked</dt>
                  <dd className="font-medium text-foreground/80">
                    {formatDateTime(apiKey.revoked_at)}
                  </dd>
                </div>
              )}
            </dl>
          </div>
        </div>

        {apiKey.is_active && (
          <div className="flex shrink-0 flex-wrap items-center gap-2">
            {confirming ? (
              <>
                <span className="text-[0.75rem] text-muted-foreground">
                  Revoke this key?
                </span>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setConfirming(false)}
                  disabled={revoking}
                >
                  Cancel
                </Button>
                <Button
                  variant="destructive"
                  size="sm"
                  onClick={onRevoke}
                  disabled={revoking}
                >
                  {revoking ? <Loader2 className="animate-spin" /> : <Trash2 />}
                  {revoking ? "Revoking…" : "Revoke"}
                </Button>
              </>
            ) : (
              <Button
                variant="outline"
                size="sm"
                onClick={() => setConfirming(true)}
                disabled={revoking}
              >
                <Trash2 />
                Revoke
              </Button>
            )}
          </div>
        )}
      </div>
    </li>
  );
}

function EmptyKeys() {
  return (
    <div className="rounded-2xl bg-card p-8 text-center ring-1 ring-foreground/10">
      <span className="mx-auto flex size-12 items-center justify-center rounded-xl bg-muted text-muted-foreground">
        <KeyRound className="size-5" />
      </span>
      <h3 className="mt-4 font-heading text-lg font-semibold">No API keys yet</h3>
      <p className="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-muted-foreground">
        Create one above to start calling the Partner API. You only need a key if
        you are integrating NutriLens into another product — the app itself does
        not use one.
      </p>
    </div>
  );
}
