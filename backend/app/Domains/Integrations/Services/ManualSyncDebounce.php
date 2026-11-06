<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * INTEGRATIONS-CENTRE-001 §4 — one manual sync per subject per two minutes, decided on the server.
 *
 * «تحديث الآن» is a button, and a button can be pressed again while the first press is still in the
 * queue. Each press opened its own run and asked the provider for the same window, so an impatient
 * operator on a slow morning was the product's own rate-limit problem. The job's uniqueness
 * (`SyncAccountMetricsJob::uniqueId`) already keeps the WORK from doubling; this keeps the request
 * from being accepted twice, so the second press is told what the first one did and when.
 *
 * The time stored is the time the sync was REQUESTED. It is returned so the refusal can name it, and
 * it must never be shown as the time the data arrived — `last_synced_at` is written by the worker
 * when figures land, and nothing here touches it.
 */
final class ManualSyncDebounce
{
    public const WINDOW_SECONDS = 120;

    /**
     * Claim the window for this subject, or return the time of the request already holding it.
     *
     * `Cache::add` is atomic on every store this product runs on, so two presses that arrive together
     * resolve to one claim and one refusal rather than two claims.
     */
    public function claim(string $subject, string $id): ?Carbon
    {
        $key = "integrations:manual-sync:{$subject}:{$id}";

        if (Cache::add($key, Carbon::now()->toIso8601String(), self::WINDOW_SECONDS)) {
            return null;
        }

        $held = Cache::get($key);

        // The entry expired between `add` and `get`: the window is free, and this press takes it.
        if (! is_string($held)) {
            Cache::put($key, Carbon::now()->toIso8601String(), self::WINDOW_SECONDS);

            return null;
        }

        return Carbon::parse($held);
    }

    /** Seconds left before the window held since `$requestedAt` opens again — never negative. */
    public function retryAfter(Carbon $requestedAt): int
    {
        return max(0, self::WINDOW_SECONDS - (int) $requestedAt->diffInSeconds(Carbon::now(), true));
    }
}
