<?php

declare(strict_types=1);

namespace App\Domains\Platform\Alerts;

use App\Domains\Billing\Gateways\PaymentGatewaySettings;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PLATFORM-PAYMENT-ALERTS-SETTLEMENT-001 — Moyasar's own record of which payments reached a settlement.
 *
 * Read-only, with the SAME gateway secret key the platform already stores for Moyasar (Owner decision 2026-11-13,
 * «نعم نفسه وتفعل»), over Basic auth exactly as `MoyasarPaymentProvider` does:
 *
 *   GET /v1/settlements?page=N&created[gt]=…   → `settlements[]` + `meta.next_page`
 *   GET /v1/settlements/{id}/lines?page=N      → `lines[]` (`payment_id`, `type`, `amount`, `transacted_at`) + `meta`
 *
 * (docs.moyasar.com/api/settlements/01-list-settlements and 03-list-settlement-lines.)
 *
 * Three honest outcomes, and only one of them can raise an alert:
 * - **read**: every page answered; the ids whose `payment` line appears are settled, the rest are not;
 * - **could not read** (network, 5xx, malformed, page cap reached): `null` — never read as «unsettled»;
 * - **refused** (401/403): the reason is logged without the key, and the source reports `refused_by_gateway` for a
 *   while, so no sweep raises anything from a key Moyasar will not honour for settlements.
 *
 * A settlement's lines never change once it exists, so each settlement's lines are cached for the lookback window and
 * a sweep only asks Moyasar for the settlement list and for settlements it has not read before.
 */
final class MoyasarSettlementSource implements SettlementSource
{
    private const API = 'https://api.moyasar.com/v1';

    /** Set for a while after Moyasar refuses the key; holds the status code and the time, never the key. */
    public const REFUSED_FLAG = 'platform_alerts:moyasar_settlements:refused';

    private const LINES_CACHE = 'platform_alerts:moyasar_settlement_lines:';

    /** Set when Moyasar reports no settlement ever for this account; re-checked after a while. */
    public const NO_HISTORY_FLAG = 'platform_alerts:moyasar_settlements:no_history';

    /** @var array{settlements: int, latest_at: ?string, since: ?string} */
    private array $coverage = ['settlements' => 0, 'latest_at' => null, 'since' => null];

    public function __construct(private readonly PaymentGatewaySettings $gateways) {}

    public function state(): string
    {
        if ($this->secretKey() === null) {
            return self::BLOCKED;
        }

        if (Cache::has(self::REFUSED_FLAG)) {
            return self::REFUSED;
        }

        return Cache::has(self::NO_HISTORY_FLAG) ? self::NO_HISTORY : self::AVAILABLE;
    }

    public function isAvailable(): bool
    {
        return $this->state() === self::AVAILABLE;
    }

    public function coverage(): array
    {
        return $this->coverage;
    }

    public function settled(string $provider, array $providerPaymentIds, Carbon $since): ?array
    {
        if ($provider !== 'moyasar' || ! $this->isAvailable()) {
            return null;
        }

        $settlements = $this->settlementsSince($since);
        if ($settlements === null) {
            return null;
        }

        /*
         * Production, 2026-11-13: the key reads settlements and the account has none at all (`total_count: 0`). Every
         * confirmed payment would then be «not in a settlement», which is true and means nothing. So with nothing since
         * the payments, ask whether the account has EVER been settled; if not, the check says so once and raises nothing.
         */
        if ($settlements === [] && $this->hasNoSettlementHistory()) {
            return null;
        }

        $settled = [];
        $latest = null;
        foreach ($settlements as $settlement) {
            $lines = $this->paymentLines($settlement['id']);
            if ($lines === null) {
                // One settlement we could not read makes the whole answer unknown: a payment might be in it.
                return null;
            }
            foreach ($lines as $paymentId) {
                $settled[$paymentId] = true;
            }
            if ($settlement['created_at'] !== null && ($latest === null || $settlement['created_at'] > $latest)) {
                $latest = $settlement['created_at'];
            }
        }

        $this->coverage = ['settlements' => count($settlements), 'latest_at' => $latest, 'since' => $since->toIso8601String()];

        return array_values(array_filter($providerPaymentIds, fn (string $id): bool => isset($settled[$id])));
    }

    /** True (and remembered for a while) when Moyasar reports no settlement ever for this account. */
    private function hasNoSettlementHistory(): bool
    {
        $body = $this->get('/settlements', ['page' => 1]);
        if ($body === null) {
            return true; // unreadable: treat as «cannot judge», which raises nothing
        }
        $total = $body['meta']['total_count'] ?? null;
        $none = (is_numeric($total) && (int) $total === 0) || (! is_numeric($total) && ($body['settlements'] ?? []) === []);
        if ($none) {
            Cache::put(self::NO_HISTORY_FLAG, ['at' => now()->toIso8601String()],
                now()->addHours(max(1, (int) config('platform_alerts.settlement_history_recheck_hours', 12))));
        }

        return $none;
    }

    /** @return list<array{id: string, created_at: ?string}>|null every settlement created on or after `$since` */
    private function settlementsSince(Carbon $since): ?array
    {
        $out = [];
        $page = 1;
        $max = $this->maxPages();

        do {
            $body = $this->get('/settlements', ['page' => $page, 'created[gt]' => $since->copy()->utc()->toIso8601String()]);
            if ($body === null || ! is_array($body['settlements'] ?? null)) {
                return null;
            }
            foreach ($body['settlements'] as $s) {
                if (is_array($s) && is_string($s['id'] ?? null) && $s['id'] !== '') {
                    $out[] = ['id' => $s['id'], 'created_at' => is_string($s['created_at'] ?? null) ? $s['created_at'] : null];
                }
            }
            $next = $body['meta']['next_page'] ?? null;
            $page = is_numeric($next) ? (int) $next : 0;
        } while ($page > 0 && $page <= $max);

        // Stopped by the cap with pages still to read: the list is incomplete, so the answer is unknown.
        return $page > $max ? null : $out;
    }

    /** @return list<string>|null the payment ids carried as `payment` lines of one settlement */
    private function paymentLines(string $settlementId): ?array
    {
        $key = self::LINES_CACHE.$settlementId;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return array_values(array_filter($cached, 'is_string'));
        }

        $ids = [];
        $page = 1;
        $max = $this->maxPages();

        do {
            $body = $this->get('/settlements/'.rawurlencode($settlementId).'/lines', ['page' => $page]);
            if ($body === null || ! is_array($body['lines'] ?? null)) {
                return null;
            }
            foreach ($body['lines'] as $line) {
                // A captured or purchased payment settles as a `payment` line; refunds, fees and duties are not arrivals.
                if (is_array($line) && ($line['type'] ?? null) === 'payment' && is_string($line['payment_id'] ?? null) && $line['payment_id'] !== '') {
                    $ids[] = $line['payment_id'];
                }
            }
            $next = $body['meta']['next_page'] ?? null;
            $page = is_numeric($next) ? (int) $next : 0;
        } while ($page > 0 && $page <= $max);

        if ($page > $max) {
            return null;
        }

        $ids = array_values(array_unique($ids));
        // A settlement's lines are final once it exists; kept for the lookback window, so each is read once.
        Cache::put($key, $ids, now()->addDays(max(1, (int) config('platform_alerts.settlement_lookback_days', 30)) + 7));

        return $ids;
    }

    /**
     * @param  array<string, int|string>  $query
     * @return array<string, mixed>|null
     */
    private function get(string $path, array $query): ?array
    {
        $key = $this->secretKey();
        if ($key === null) {
            return null;
        }

        try {
            /** @var Response $response */
            $response = Http::withBasicAuth($key, '')->timeout(15)->acceptJson()->get(self::API.$path, $query);
        } catch (Throwable $e) {
            Log::warning('platform_alerts.moyasar_settlements.unreachable', ['path' => $this->pathFor($path), 'error' => class_basename($e)]);

            return null;
        }

        if (in_array($response->status(), [401, 403], true)) {
            /*
             * The key is refused for settlements. Logged with the status and Moyasar's own message type, never the key;
             * the source then reports itself refused for a while so no sweep keeps asking or alerting.
             */
            $type = $response->json('type');
            Log::warning('platform_alerts.moyasar_settlements.refused', [
                'status' => $response->status(),
                'type' => is_string($type) ? mb_substr($type, 0, 60) : null,
                'path' => $this->pathFor($path),
            ]);
            Cache::put(self::REFUSED_FLAG, ['status' => $response->status(), 'at' => now()->toIso8601String()],
                now()->addHours(max(1, (int) config('platform_alerts.settlement_refused_hours', 6))));

            return null;
        }

        if (! $response->successful()) {
            Log::warning('platform_alerts.moyasar_settlements.unread', ['status' => $response->status(), 'path' => $this->pathFor($path)]);

            return null;
        }

        $body = $response->json();

        return is_array($body) ? $body : null;
    }

    /** The path without the settlement id, so a log line carries no identifier it does not need. */
    private function pathFor(string $path): string
    {
        return str_contains($path, '/lines') ? '/settlements/{id}/lines' : $path;
    }

    private function maxPages(): int
    {
        return max(1, (int) config('platform_alerts.settlement_max_pages', 50));
    }

    private function secretKey(): ?string
    {
        $key = $this->gateways->value('moyasar', 'secret_key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
