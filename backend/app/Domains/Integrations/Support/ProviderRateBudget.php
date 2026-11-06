<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Support;

use App\Domains\Integrations\Exceptions\RateBudgetExhausted;
use App\Domains\Integrations\MetaCandidate\MetaUsageHeader;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * INTEG-SYNC-ENGINE-001 — how many requests a provider and a connection may spend per window, and what the provider itself said.
 *
 * ## Where it bites
 *
 * Every connector request is COUNTED, whoever made it, because every one spends the same provider quota. Only a sync job is REFUSED:
 * the job asks `delayFor()` before it begins and runs inside `enforcing()`, so a budget spent mid-window stops the read before the
 * request leaves. An operator's campaign write counts and is never refused by a sweep's budget.
 *
 * ## One connection never starves another
 *
 * A connection's limit is capped at a share of the provider pool, so no single customer can drain it. A refused request charges
 * neither counter. A hold (below) belongs to one connection. A job that cannot run is released, so the one sync worker moves straight
 * on to the next account instead of waiting.
 *
 * ## What the provider said
 *
 * `Retry-After` on a 429 or 503 holds the connection until then. For Meta, `X-Business-Use-Case-Usage` does too, when its documented
 * `estimated_time_to_regain_access` is above zero or a counter reaches the configured ceiling. A successful answer clears a hold.
 * `PlatformHttp::backoff()` still waits inside the call for short delays; a hold is what stops the NEXT job walking into the same wall.
 *
 * ## Losing the cache loses the budget, not the sync
 *
 * This is a courtesy to the provider, not a permission. Authorisation fails closed; a budget that cannot be read allows.
 *
 * Registered `scoped`, so the per-job facts below (`requestsSeen`, `failureKind`) start empty for every job a worker takes.
 */
final class ProviderRateBudget
{
    public const RATE_BUDGET = 'rate_budget';

    public const RATE_LIMITED = 'rate_limited';

    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const CONNECTION_FAILED = 'connection_failed';

    /** Meta's usage is a rolling hour and stated no regain time: look again in five minutes rather than guess. */
    private const USAGE_CEILING_HOLD_SECONDS = 300;

    private bool $enforcing = false;

    /** @var array<string,int> */
    private array $seen = [];

    /** @var array<string,string|null> what the last request on each connection came to */
    private array $last = [];

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function enforcing(Closure $work): mixed
    {
        $this->enforcing = true;

        try {
            return $work();
        } finally {
            $this->enforcing = false;
        }
    }

    /** A job starts with no facts about its connection: on an inline queue one instance serves every job of the process. */
    public function beginJob(string $connectionId): void
    {
        unset($this->seen[$connectionId], $this->last[$connectionId]);
    }

    /** Seconds until this provider and connection may be asked again, or null when they may be asked now. */
    public function delayFor(string $provider, string $connectionId): ?int
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->guarded(function () use ($provider, $connectionId): ?int {
            $held = (int) $this->store()->get($this->holdKey($connectionId), 0);
            $wait = $held - $this->now();

            if ($wait > 0) {
                return $wait;
            }

            $spentAll = (int) $this->store()->get($this->counterKey($provider), 0) >= $this->providerLimit($provider)
                || (int) $this->store()->get($this->counterKey($provider, $connectionId), 0) >= $this->connectionLimit($provider);

            return $spentAll ? $this->secondsLeftInWindow() : null;
        });
    }

    /** Count one request. Inside `enforcing()`, refuse it instead when the budget is spent; a refused request charges nothing. */
    public function beforeRequest(string $provider, string $connectionId): void
    {
        $this->last[$connectionId] = self::CONNECTION_FAILED; // until an answer says otherwise: a connection failure leaves no response

        if (! $this->enabled()) {
            return;
        }

        if ($this->enforcing && $this->delayFor($provider, $connectionId) !== null) {
            $this->last[$connectionId] = self::RATE_BUDGET;

            throw new RateBudgetExhausted("The request budget for {$provider} on this connection is spent, or {$provider} asked us to wait, so the request was not sent and nothing from this window was stored.");
        }

        $this->seen[$connectionId] = ($this->seen[$connectionId] ?? 0) + 1;

        $this->guarded(function () use ($provider, $connectionId): null {
            $ttl = $this->windowSeconds() * 2;

            foreach ([$this->counterKey($provider), $this->counterKey($provider, $connectionId)] as $key) {
                $this->store()->add($key, 0, $ttl);
                $this->store()->increment($key);
            }

            return null;
        });
    }

    public function afterResponse(string $provider, string $connectionId, ResponseInterface $response): void
    {
        $status = $response->getStatusCode();

        $this->last[$connectionId] = match (true) {
            $status === 429 => self::RATE_LIMITED,
            $status >= 500 => self::PROVIDER_UNAVAILABLE,
            default => null,
        };

        if (! $this->enabled()) {
            return;
        }

        $hold = max(
            in_array($status, [429, 503], true) ? $this->secondsFromRetryAfter($response->getHeaderLine('Retry-After')) : 0,
            $provider === 'meta' ? $this->secondsFromMetaUsage($response->getHeaderLine('X-Business-Use-Case-Usage')) : 0,
        );

        $this->guarded(function () use ($connectionId, $status, $hold): null {
            if ($hold > 0) {
                $seconds = min($hold, $this->maxHoldSeconds());
                $this->store()->put($this->holdKey($connectionId), $this->now() + $seconds, $seconds);
            } elseif ($status >= 200 && $status < 300) {
                $this->store()->forget($this->holdKey($connectionId));
            }

            return null;
        });
    }

    /** Why the last request on this connection came to nothing a retry could not change, or null when it was something else. */
    public function failureKind(string $connectionId): ?string
    {
        return $this->last[$connectionId] ?? null;
    }

    public function requestsSeen(string $connectionId): int
    {
        return $this->seen[$connectionId] ?? 0;
    }

    public function providerLimit(string $provider): int
    {
        $own = config("integrations.rate_budget.providers.{$provider}");

        return is_numeric($own) ? (int) $own : (int) config('integrations.rate_budget.provider_requests', 30000);
    }

    public function connectionLimit(string $provider): int
    {
        $share = (float) config('integrations.rate_budget.connection_share', 0.5);

        return min(
            (int) config('integrations.rate_budget.connection_requests', 15000),
            max(1, (int) floor($this->providerLimit($provider) * $share)),
        );
    }

    private function secondsFromRetryAfter(string $header): int
    {
        if (is_numeric($header)) {
            return max(0, (int) ceil((float) $header));
        }

        if ($header === '') {
            return 0;
        }

        $at = strtotime($header);

        return $at === false ? 0 : max(0, $at - $this->now());
    }

    private function secondsFromMetaUsage(string $header): int
    {
        $usage = MetaUsageHeader::summarise($header);
        $regain = MetaUsageHeader::regainAccessMinutes($header);

        if ($regain > 0) {
            return $regain * 60;
        }

        $ceiling = (int) config('integrations.rate_budget.meta_usage_hold_percent', 100);
        $peak = max((int) $usage['max_call_count'], (int) $usage['max_total_cputime'], (int) $usage['max_total_time']);

        return $usage['present'] && $peak >= $ceiling ? self::USAGE_CEILING_HOLD_SECONDS : 0;
    }

    private function secondsLeftInWindow(): int
    {
        $window = $this->windowSeconds();

        return $window - ($this->now() % $window);
    }

    private function counterKey(string $provider, ?string $connectionId = null): string
    {
        $bucket = intdiv($this->now(), $this->windowSeconds());

        return 'sync-budget:'.$provider.($connectionId === null ? '' : ':'.$connectionId).':'.$bucket;
    }

    private function holdKey(string $connectionId): string
    {
        return 'sync-budget-hold:'.$connectionId;
    }

    private function now(): int
    {
        return Carbon::now()->getTimestamp();
    }

    private function enabled(): bool
    {
        return (bool) config('integrations.rate_budget.enabled', true);
    }

    private function windowSeconds(): int
    {
        return max(1, (int) config('integrations.rate_budget.window_seconds', 3600));
    }

    private function maxHoldSeconds(): int
    {
        return max(1, (int) config('integrations.rate_budget.max_hold_seconds', 3600));
    }

    private function store(): Repository
    {
        return Cache::store(config('integrations.rate_budget.cache_store'));
    }

    /**
     * @param  Closure(): (int|null)  $read
     */
    private function guarded(Closure $read): ?int
    {
        try {
            return $read();
        } catch (Throwable $e) {
            Log::warning('The provider rate budget could not be read, so it was not applied.', ['exception' => $e::class]);

            return null;
        }
    }
}
