<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Audience;

use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Providers\ReportsAudienceBreakdowns;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Integrations\Support\ProviderErrorText;
use App\Domains\Metrics\Models\MetricSyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * AUDIENCE-001 — reads a provider's PAID DELIVERY breakdowns and stores them, contained.
 *
 * - A separate table and a separate call: a refusal here never costs the campaign figures, and it
 *   never turns a healthy run red. What happened is written to the run's `meta.audience`.
 * - Throttled: one sweep per account per `MIN_INTERVAL_MINUTES`, because a breakdown is enrichment
 *   and the campaign sweep runs every thirty minutes.
 * - Identity is the structure sweep's: a row naming an ad set we never discovered under THIS
 *   project is skipped and counted, never invented (SEC-CLUSTER-2).
 * - The write re-proves the binding (`whileBound`), like every other grain.
 */
final class AudienceBreakdownSync
{
    public const MIN_INTERVAL_MINUTES = 360;

    public function __construct(private readonly AccountAssignment $assignment) {}

    /** @return array<string, mixed> what happened, per dimension — stored on the run */
    public function sync(ReportsAudienceBreakdowns $connector, ExternalAccount $account, MetricSyncRun $run, Carbon $from, Carbon $to): array
    {
        $projectId = (string) $run->project_id;
        // Throttled on the last ATTEMPT, not the last stored row: a sweep that stores nothing (no day reached the provider's
        // floor, or every question was refused) must not be asked again every thirty minutes.
        $attemptKey = 'audience-attempt|'.$account->getKey().'|'.$projectId;
        $last = DB::table('audience_breakdown_rows')->where('external_account_id', $account->getKey())->where('project_id', $projectId)->max('synced_at');
        $attempted = Cache::get($attemptKey);
        if (is_string($attempted) || ($last !== null && Carbon::parse((string) $last)->gt(now()->subMinutes(self::MIN_INTERVAL_MINUTES)))) {
            return ['state' => 'throttled', 'last_synced_at' => $last === null ? null : Carbon::parse((string) $last)->toIso8601String()];
        }
        Cache::put($attemptKey, now()->toIso8601String(), now()->addMinutes(self::MIN_INTERVAL_MINUTES));

        $campaigns = ExternalCampaign::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())->where('project_id', $projectId)
            ->pluck('id', 'external_id');
        $sets = ExternalAdSet::withoutGlobalScopes()
            ->whereIn('external_campaign_id', $campaigns->values())->where('project_id', $projectId)
            ->get(['id', 'external_id', 'external_campaign_id', 'tenant_id'])
            ->keyBy(static fn (ExternalAdSet $s): string => (string) $s->external_id);

        // The ad sets that DELIVERED in the window, and on which days — what a per-ad-set provider asks about, and nothing else.
        $delivering = [];
        $byId = $sets->mapWithKeys(static fn (ExternalAdSet $s): array => [(string) $s->getKey() => (string) $s->external_id]);
        foreach (DB::table('entity_daily_metrics')
            ->where('tenant_id', (string) $account->tenant_id)->where('project_id', $projectId)
            ->where('external_account_id', (string) $account->getKey())->where('entity_type', 'ad_set')
            ->tap(fn ($q) => BoundAccountVisibility::applyToEntityMetrics($q, 'entity_daily_metrics'))
            ->where('impressions', '>=', max(1, $connector->audienceMinimumImpressions()))->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->get(['entity_id', 'metric_date']) as $d) {
            $ext = $byId[(string) $d->entity_id] ?? null;
            if ($ext !== null) {
                $delivering[$ext][] = substr((string) $d->metric_date, 0, 10);
            }
        }

        $out = [];
        foreach ($connector->audienceDimensions() as $dimension) {
            // A (ad set, day) already stored for this dimension is not asked again, so a capped sweep moves on to the rest.
            $stored = [];
            foreach (DB::table('audience_breakdown_rows')->where('project_id', $projectId)->where('external_account_id', (string) $account->getKey())
                ->where('dimension', $dimension)->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
                ->distinct()->get(['external_entity_id', 'metric_date']) as $r) {
                $stored[(string) $r->external_entity_id.'|'.substr((string) $r->metric_date, 0, 10)] = true;
            }
            $ask = [];
            foreach ($delivering as $ext => $days) {
                $left = array_values(array_filter(array_unique($days), static fn (string $d): bool => ! isset($stored[$ext.'|'.$d])));
                if ($left !== []) {
                    $ask[$ext] = $left;
                }
            }
            try {
                $result = $connector->audienceBreakdown((string) $account->external_id, $dimension, $from->toDateString(), $to->toDateString(), $ask);
            } catch (Throwable $e) {
                $out[$dimension] = ['state' => 'failed', 'reason' => Str::limit((string) ProviderErrorText::forStorage($e->getMessage()), 300)];

                continue;
            }
            if (! $result->success) {
                $out[$dimension] = ['state' => 'failed', 'reason' => Str::limit((string) ProviderErrorText::forStorage($result->message ?? 'refused'), 300)];

                continue;
            }

            $written = 0;
            $skipped = 0;
            $held = $this->assignment->whileBound($account, $projectId, function () use ($result, $sets, $account, $run, $dimension, $connector, &$written, &$skipped): void {
                $now = now();
                foreach ($result->records as $row) {
                    $set = $sets->get((string) ($row['entity_id'] ?? ''));
                    if ($set === null || (string) $set->tenant_id !== (string) $account->tenant_id) {
                        $skipped++;

                        continue;
                    }
                    $rowCurrency = isset($row['currency']) ? strtoupper((string) $row['currency']) : null;
                    $accountCurrency = $account->currency !== null && $account->currency !== '' ? strtoupper((string) $account->currency) : null;
                    $currency = $rowCurrency ?? $accountCurrency;
                    $hasSpend = array_key_exists('spend', $row);
                    $values = [
                        'tenant_id' => (string) $account->tenant_id,
                        'provider' => (string) $account->provider,
                        'source_field' => $connector->audienceSourceField($dimension),
                        'external_campaign_id' => (string) $set->external_campaign_id,
                        'external_ad_set_id' => (string) $set->getKey(),
                        'impressions' => $row['impressions'] ?? null,
                        'reach' => $row['reach'] ?? null,
                        'clicks' => $row['clicks'] ?? null,
                        // MONEY-CURRENCY-NEVER-ASSUMED-001 — no currency, no money.
                        'spend_original' => $hasSpend && $currency !== null ? $row['spend'] : null,
                        'original_currency' => $hasSpend ? $currency : null,
                        'currency_source' => ! $hasSpend || $currency === null ? null : ($rowCurrency !== null ? 'provider_row' : 'account'),
                        'sync_run_id' => (string) $run->getKey(),
                        'synced_at' => $now,
                        'updated_at' => $now,
                    ];
                    $key = [
                        'project_id' => (string) $run->project_id,
                        'external_account_id' => (string) $account->getKey(),
                        'dimension' => $dimension,
                        'external_entity_id' => (string) $row['entity_id'],
                        'metric_date' => (string) $row['date'],
                        'value' => Str::limit((string) $row['value'], 250, ''),
                    ];
                    // One statement, safe under a concurrent run: a re-bound account never rewrites another project's history.
                    DB::table('audience_breakdown_rows')->upsert(
                        [$key + $values + ['id' => (string) Str::uuid(), 'created_at' => $now]],
                        array_keys($key),
                        array_keys($values),
                    );
                    $written++;
                }
            });

            $out[$dimension] = ! $held
                ? ['state' => 'unlinked', 'fetched' => count($result->records)]
                : ['state' => 'stored', 'fetched' => count($result->records), 'stored' => $written, 'skipped_unknown_ad_set' => $skipped];
        }

        return $out;
    }
}
