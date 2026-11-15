<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Fx;

use App\Domains\Audit\AuditLogger;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * FX-ENGINE-001 — the ONE writer of exchange rates. Append-only history, an audit row on every change.
 *
 * - Every write inserts a `currency_rate_versions` row; nothing in that table is ever updated or deleted.
 * - `currency_rates` (one live row per pair and day) is a projection of the latest APPLIED version.
 * - A MANUAL rate wins over an AUTOMATIC one for the same day: an automatic quote for a day that already has a manual
 *   rate is recorded as `shadowed` and does not replace it. Any other write becomes version n+1 of that day.
 * - Audit: who, when, before and after, on every write (`fx.rate.recorded` · `fx.rate.edited` · `fx.rate.shadowed`).
 */
final class RateLedger
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{rate: CurrencyRate, outcome: 'applied'|'shadowed', version: int}
     */
    public function record(
        string $base,
        string $quote,
        float $rate,
        Carbon|string $date,
        string $kind,
        ?string $source = null,
        Carbon|string|null $effectiveTo = null,
        ?string $note = null,
        ?User $by = null,
    ): array {
        $base = strtoupper(trim($base));
        $quote = strtoupper(trim($quote));
        if (! in_array($kind, [CurrencyRate::MANUAL, CurrencyRate::PEGGED, CurrencyRate::AUTOMATIC], true)) {
            throw new InvalidArgumentException("Unknown rate kind: {$kind}");
        }
        if ($rate <= 0 || $base === $quote) {
            // A zero rate converts a real amount into «spent nothing»; a currency is at par with itself.
            throw new InvalidArgumentException('A rate must be positive and between two different currencies.');
        }

        $day = $date instanceof Carbon ? $date->toDateString() : Carbon::parse($date)->toDateString();
        $until = $effectiveTo === null ? null : ($effectiveTo instanceof Carbon ? $effectiveTo->toDateString() : Carbon::parse($effectiveTo)->toDateString());
        $source ??= match ($kind) {
            CurrencyRate::MANUAL => 'manual:'.($by->email ?? 'system'),
            CurrencyRate::PEGGED => 'pegged:'.($by->email ?? 'system'),
            default => 'automatic',
        };
        $rate = round($rate, 12);

        return DB::transaction(function () use ($base, $quote, $rate, $day, $until, $kind, $source, $note, $by): array {
            /** @var CurrencyRate|null $live */
            $live = CurrencyRate::query()->where('base_currency', $base)->where('quote_currency', $quote)
                ->whereDate('rate_date', $day)->lockForUpdate()->first();

            $liveKind = $live === null ? null : (string) ($live->kind ?? CurrencyRate::kindOf($live->source));
            $version = $live === null ? 1 : max((int) $live->version, (int) DB::table('currency_rate_versions')
                ->where('currency_rate_id', $live->getKey())->max('version')) + 1;
            $shadowed = $live !== null && $liveKind === CurrencyRate::MANUAL && $kind === CurrencyRate::AUTOMATIC;
            $before = $live === null ? null : $this->snapshot($live);
            $versionId = (string) Str::uuid();

            if (! $shadowed) {
                $attributes = [
                    'rate' => $rate, 'source' => $source, 'kind' => $kind, 'effective_to' => $until, 'note' => $note,
                    'recorded_by' => $by?->id, 'recorded_by_email' => $by?->email, 'version' => $version,
                    'current_version_id' => $versionId,
                ];
                if ($live === null) {
                    $live = CurrencyRate::query()->create(['base_currency' => $base, 'quote_currency' => $quote, 'rate_date' => $day] + $attributes);
                } else {
                    $live->forceFill($attributes)->save();
                }
            }

            DB::table('currency_rate_versions')->insert([
                'id' => $versionId, 'currency_rate_id' => $live->getKey(), 'base_currency' => $base, 'quote_currency' => $quote,
                'rate' => $rate, 'previous_rate' => $before['rate'] ?? null, 'rate_date' => $day, 'effective_to' => $until,
                'kind' => $kind, 'source' => $source, 'note' => $note, 'version' => $version,
                'outcome' => $shadowed ? 'shadowed' : 'applied',
                'recorded_by' => $by?->id, 'recorded_by_email' => $by?->email, 'created_at' => now(),
            ]);

            $this->audit->log(
                action: $shadowed ? 'fx.rate.shadowed' : ($before === null ? 'fx.rate.recorded' : 'fx.rate.edited'),
                entityType: CurrencyRate::class,
                entityId: (string) $live->getKey(),
                before: $before,
                after: ['base' => $base, 'quote' => $quote, 'rate' => $rate, 'rate_date' => $day, 'effective_to' => $until,
                    'kind' => $kind, 'source' => $source, 'note' => $note, 'version' => $version, 'outcome' => $shadowed ? 'shadowed' : 'applied'],
                userId: $by?->id,
            );

            if (! $shadowed) {
                // After the commit: the release reads the rate it is about to use, and a rolled-back write releases nothing.
                DB::afterCommit(fn () => $this->release($day));
            }

            return ['rate' => $live->refresh(), 'outcome' => $shadowed ? 'shadowed' : 'applied', 'version' => $version];
        });
    }

    /**
     * PROJECT-LIFECYCLE-001 §7 (N4) — a stored figure withheld for want of a rate converts itself the day the rate exists
     * (`ReportingCurrency`'s promise, kept): from the rate's own day (at most {@see FxRecompute::MAX_DAYS} back) to today,
     * every NULL whose original is now convertible is filled, and the budgets of the projects touched are refreshed. Figures
     * already converted are never re-priced here. Nothing is written for a rate dated in the future.
     */
    private function release(string $day): void
    {
        $today = Carbon::today();
        $from = Carbon::parse($day)->max($today->copy()->subDays(FxRecompute::MAX_DAYS - 1));
        if ($from->gt($today)) {
            return;
        }

        app(FxRecompute::class)->run($from, $today, apply: true, onlyWithheld: true);
    }

    /**
     * Every version of a pair, newest first — the history the admin page shows.
     *
     * @return list<array<string, mixed>>
     */
    public function history(string $base, string $quote, int $limit = 100): array
    {
        return DB::table('currency_rate_versions')
            ->where('base_currency', strtoupper($base))->where('quote_currency', strtoupper($quote))
            ->orderByDesc('created_at')->orderByDesc('version')->limit($limit)
            ->get()
            ->map(static fn ($v): array => [
                'id' => (string) $v->id,
                'rate' => (float) $v->rate,
                'previous_rate' => $v->previous_rate === null ? null : (float) $v->previous_rate,
                'rate_date' => Carbon::parse((string) $v->rate_date)->toDateString(),
                'effective_to' => $v->effective_to === null ? null : Carbon::parse((string) $v->effective_to)->toDateString(),
                'kind' => (string) $v->kind,
                'source' => (string) $v->source,
                'note' => $v->note,
                'version' => (int) $v->version,
                'outcome' => (string) $v->outcome,
                'recorded_by' => $v->recorded_by_email,
                'recorded_at' => Carbon::parse((string) $v->created_at)->toIso8601String(),
            ])->all();
    }

    /** @return array<string, mixed> */
    private function snapshot(CurrencyRate $r): array
    {
        return [
            'rate' => (float) $r->rate,
            'rate_date' => Carbon::parse((string) $r->rate_date)->toDateString(),
            'effective_to' => $r->effective_to === null ? null : Carbon::parse((string) $r->effective_to)->toDateString(),
            'kind' => (string) ($r->kind ?? CurrencyRate::kindOf($r->source)),
            'source' => (string) $r->source,
            'note' => $r->note,
            'version' => (int) $r->version,
        ];
    }
}
