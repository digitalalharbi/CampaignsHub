<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Fx;

use App\Domains\Metrics\Services\CurrencyConverter;
use App\Domains\Platform\Models\PlatformAlert;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * FX-ENGINE-001 safety net — the platform admin is told, in the app, when a currency in use cannot be converted.
 *
 * - `fx_rate_missing`: an ad account is linked in a currency with no rate (raised at link time by the ExternalAccount
 *   hook and by the daily sweep);
 * - `fx_rate_stale`: a used currency's latest rate is older than 7 days.
 *
 * Filed in the platform inbox (`platform_alerts`, in-app only — never mailed), one open alert per currency, and closed
 * by the sweep once the rate exists. Nothing here touches an ad account.
 */
final class FxMonitor
{
    public const MISSING = 'fx_rate_missing';

    public const STALE = 'fx_rate_stale';

    public const KINDS = [self::MISSING, self::STALE];

    public function __construct(private readonly CurrencyCoverage $coverage, private readonly FxEngine $fx) {}

    /** @return array{raised: int, cleared: int, open: int} */
    public function sweep(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $raised = 0;
        $keep = [];

        foreach ($this->coverage->rows($now->copy()->startOfDay()) as $row) {
            if ($row['accounts'] === 0 || ! in_array($row['status'], ['missing', 'stale'], true)) {
                continue;
            }
            $kind = $row['status'] === 'missing' ? self::MISSING : self::STALE;
            $key = "fx:{$kind}:{$row['currency']}";
            $keep[] = $key;
            $raised += $this->file($kind, $key, $row, $now) ? 1 : 0;
        }

        $cleared = PlatformAlert::query()->open()->whereIn('kind', self::KINDS)
            ->when($keep !== [], static fn ($q) => $q->whereNotIn('dedup_key', $keep))
            ->update(['resolved_at' => $now]);

        return ['raised' => $raised, 'cleared' => $cleared, 'open' => PlatformAlert::query()->open()->whereIn('kind', self::KINDS)->count()];
    }

    /** At link time: one cheap lookup for the account's currency, an alert only when it has no rate today. */
    public function accountLinked(string $currency, string $provider, ?string $tenantId): void
    {
        $currency = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1 || $currency === CurrencyConverter::BASE) {
            return;
        }
        if (! $this->fx->convert(1, $currency, CurrencyConverter::BASE, Carbon::today())->withheld) {
            return;
        }
        $this->file(self::MISSING, 'fx:'.self::MISSING.':'.$currency, [
            'currency' => $currency, 'accounts' => 1, 'providers' => [$provider], 'withheld' => 0, 'rate_date' => null,
        ], Carbon::now(), $tenantId);
    }

    /** @return list<array<string, mixed>> the open FX alerts, newest first, for the rates page */
    public function open(): array
    {
        return PlatformAlert::query()->open()->whereIn('kind', self::KINDS)->orderByDesc('last_seen_at')->limit(50)->get()
            ->map(static fn (PlatformAlert $a): array => [
                'id' => (string) $a->getKey(), 'kind' => (string) $a->kind, 'severity' => (string) $a->severity,
                'currency' => (string) ($a->facts['currency'] ?? ''), 'accounts' => (int) ($a->facts['accounts'] ?? 0),
                'withheld' => (int) ($a->facts['withheld'] ?? 0), 'rate_date' => $a->facts['rate_date'] ?? null,
                'since' => $a->first_seen_at?->toIso8601String(),
            ])->all();
    }

    /** @param  array<string, mixed>  $row */
    private function file(string $kind, string $key, array $row, Carbon $now, ?string $tenantId = null): bool
    {
        $facts = [
            'currency' => $row['currency'], 'accounts' => $row['accounts'], 'providers' => $row['providers'] ?? [],
            'withheld' => $row['withheld'] ?? 0, 'rate_date' => $row['rate_date'] ?? null, 'rates_page' => FxConversion::RATES_PAGE,
        ];
        $existing = PlatformAlert::query()->where('dedup_key', $key)->first();
        if ($existing !== null) {
            $existing->forceFill(['facts' => $facts, 'last_seen_at' => $now, 'resolved_at' => null])->save();

            return false;
        }

        try {
            PlatformAlert::query()->create([
                'kind' => $kind, 'severity' => $kind === self::MISSING ? 'critical' : 'warning', 'dedup_key' => $key,
                'subject_type' => 'currency', 'subject_id' => null, 'subject_tenant_id' => $tenantId, 'facts' => $facts,
                'occurrences' => 1, 'first_seen_at' => $now, 'last_seen_at' => $now,
                // In-app only: the platform inbox shows it; the payment-alert mailer never addresses FX kinds.
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
