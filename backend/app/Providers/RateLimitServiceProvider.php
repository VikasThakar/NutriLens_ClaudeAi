<?php

namespace App\Providers;

use App\Models\ApiKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Named rate limiters.
 *
 * The shape of the limits follows what each endpoint actually costs:
 *
 *  - Endpoints that do no upstream work are limited generously; they only need
 *    protecting from a runaway client.
 *  - Endpoints that call an AI provider are limited tightly, because every call
 *    costs real money and a leaked key should not be able to spend much of it.
 *  - Image analysis is the most expensive of all and is limited hardest.
 *
 * Partner limits are keyed on the **API key**, not the IP: a partner behind one
 * NAT should not throttle their own colleagues, and an attacker rotating IPs
 * should not get more budget. Unauthenticated requests fall back to the IP so a
 * flood of keyless calls still costs something.
 */
class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Cheap partner endpoints — key verification, metadata.
        RateLimiter::for('partner-api', fn (Request $request) => Limit::perMinute(120)
            ->by($this->partnerKey($request)));

        // Structured food estimation: one text call per request.
        RateLimiter::for('partner-estimate', fn (Request $request) => [
            Limit::perMinute(30)->by($this->partnerKey($request)),
            Limit::perDay(1000)->by($this->partnerKey($request)),
        ]);

        // Image analysis: a vision call per request, the most expensive thing
        // NutriLens does.
        RateLimiter::for('partner-analyze', fn (Request $request) => [
            Limit::perMinute(10)->by($this->partnerKey($request)),
            Limit::perDay(250)->by($this->partnerKey($request)),
        ]);

        // First-party: creating API keys. Not expensive, but a spam of keys is
        // a mess for the user to clean up.
        RateLimiter::for('api-keys', fn (Request $request) => Limit::perMinute(20)
            ->by($request->user()?->id ?: $request->ip()));

        /*
         * AI Coach messages: one provider call each.
         *
         * Two limits, because they guard different things. The per-minute one
         * is generous enough that a real conversation — including reading a
         * reply, thinking, and asking a follow-up — never touches it; it exists
         * to stop a loop or a held-down send button. The daily one is the cost
         * ceiling for a single compromised token.
         */
        RateLimiter::for('ai-coach', fn (Request $request) => [
            Limit::perMinute(15)->by($this->userKey($request)),
            Limit::perDay(150)->by($this->userKey($request)),
        ]);

        // Starting a thread costs nothing upstream, so this is only here to
        // stop an empty conversation being created in a loop.
        RateLimiter::for('ai-coach-threads', fn (Request $request) => Limit::perMinute(30)
            ->by($this->userKey($request)));
    }

    /**
     * The limiter bucket for a first-party request: the authenticated user,
     * falling back to the IP for a call that never reaches auth.
     */
    private function userKey(Request $request): string
    {
        return $request->user()
            ? "user:{$request->user()->id}"
            : 'ip:'.($request->ip() ?? 'unknown');
    }

    /**
     * The limiter bucket for a partner request: the API key's id once
     * authenticated, otherwise the caller's IP.
     *
     * Uses the key id rather than the key itself, so no secret is written into
     * a cache key.
     */
    private function partnerKey(Request $request): string
    {
        $key = $request->attributes->get('api_key');

        return $key instanceof ApiKey
            ? "api-key:{$key->id}"
            : 'ip:'.($request->ip() ?? 'unknown');
    }
}
