<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Actions;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Integrations\Models\ExternalAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * STRUCT-001 — the one place a platform's hierarchy becomes rows.
 *
 * ## Everything is resolved against what we already hold
 *
 * A connector returns the PLATFORM's ids — `campaign_external_id`, `ad_set_external_id` — because an
 * adapter has never seen our tables. This action turns them into our ids, and a row naming a parent we
 * have not discovered is SKIPPED and counted rather than attached to a guess. The count is what makes
 * a run report itself as partial, which is how "the campaign sync ran before the ad-set sync" becomes
 * visible instead of looking like a platform with no ad sets.
 *
 * ## Tenant and project are stated, never inherited
 *
 * This runs from a queue worker, where there is no request and therefore no project context to fall
 * back on. Every write goes out `withoutGlobalScopes()` with explicit ids taken from the campaign the
 * row hangs off — so an ad set is always filed under the same project as its campaign, and a context
 * left over from a previous job cannot re-file it somewhere else.
 *
 * ## Idempotent by construction
 *
 * Ad sets are keyed by `(external_campaign_id, external_id)` and ads by the same pair — the database's
 * own unique indexes — so a re-sync updates in place. Nothing here deletes: an ad set that stopped
 * being returned may have been archived on the platform, and dropping the row would take its history
 * with it. Its `last_synced_at` simply stops moving, which is the honest signal.
 */
final class ImportExternalStructure
{
    /**
     * @param  list<array<string,mixed>>  $adSets
     * @param  list<array<string,mixed>>  $ads
     * @return array{ad_sets:int,ads:int,creatives:int,skipped:int}
     */
    public function execute(ExternalAccount $account, array $adSets, array $ads): array
    {
        $campaigns = ExternalCampaign::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->get(['id', 'external_id', 'project_id', 'tenant_id', 'unified_campaign_id'])
            ->keyBy('external_id');

        $counts = ['ad_sets' => 0, 'ads' => 0, 'creatives' => 0, 'skipped' => 0];

        DB::transaction(function () use ($account, $adSets, $ads, $campaigns, &$counts): void {
            // Platform ad-set id → the row we just wrote, so the ads pass can resolve its parent
            // without a second query per ad.
            $setsByExternalId = [];

            foreach ($adSets as $row) {
                $externalId = (string) ($row['external_id'] ?? '');
                $campaign = $campaigns->get((string) ($row['campaign_external_id'] ?? ''));

                if ($externalId === '' || $campaign === null) {
                    $counts['skipped']++;

                    continue;
                }

                $set = ExternalAdSet::withoutGlobalScopes()->updateOrCreate(
                    ['external_campaign_id' => $campaign->id, 'external_id' => $externalId],
                    [
                        'tenant_id' => $campaign->tenant_id,
                        'project_id' => $campaign->project_id,
                        'unified_campaign_id' => $campaign->unified_campaign_id,
                        'provider' => $account->provider,
                        'name' => (string) ($row['name'] ?? $externalId),
                        'status' => (string) ($row['status'] ?? 'unknown'),
                        'optimization_goal' => $row['optimization_goal'] ?? null,
                        'bid_strategy' => $row['bid_strategy'] ?? null,
                        'daily_budget' => $row['daily_budget'] ?? null,
                        'lifetime_budget' => $row['lifetime_budget'] ?? null,
                        'currency' => $row['currency'] ?? $account->currency,
                        'targeting' => $row['targeting'] ?? null,
                        'starts_at' => $this->time($row['starts_at'] ?? null),
                        'ends_at' => $this->time($row['ends_at'] ?? null),
                        'source_type' => 'api',
                        'is_demo' => false,
                        'last_synced_at' => Carbon::now(),
                    ],
                );

                $setsByExternalId[$externalId] = $set;
                $counts['ad_sets']++;
            }

            foreach ($ads as $row) {
                $externalId = (string) ($row['external_id'] ?? '');
                $set = $setsByExternalId[(string) ($row['ad_set_external_id'] ?? '')] ?? null;

                /*
                 * The campaign comes from the ad when the platform states it, and from the ad set when
                 * it does not — Snapchat names only the squad on an ad, and X only the line item.
                 * Reading it off the parent is a fact, not an inference.
                 */
                $campaign = $campaigns->get((string) ($row['campaign_external_id'] ?? ''))
                    ?? ($set === null ? null : $campaigns->firstWhere('id', $set->external_campaign_id));

                if ($externalId === '' || $campaign === null) {
                    $counts['skipped']++;

                    continue;
                }

                $creativeId = $this->creativeFor($account, $campaign, $row['creative'] ?? null, $externalId, $counts);

                ExternalAd::withoutGlobalScopes()->updateOrCreate(
                    ['external_campaign_id' => $campaign->id, 'external_id' => $externalId],
                    [
                        'tenant_id' => $campaign->tenant_id,
                        'project_id' => $campaign->project_id,
                        'external_ad_set_id' => $set?->getKey(),
                        'unified_campaign_id' => $campaign->unified_campaign_id,
                        'creative_id' => $creativeId,
                        'provider' => $account->provider,
                        'name' => (string) ($row['name'] ?? $externalId),
                        'status' => (string) ($row['status'] ?? 'unknown'),
                        'review_status' => $row['review_status'] ?? null,
                        'destination_url' => $row['destination_url'] ?? null,
                        'source_type' => 'api',
                        'is_demo' => false,
                        'last_synced_at' => Carbon::now(),
                    ],
                );

                $counts['ads']++;
            }
        });

        return $counts;
    }

    /**
     * Upsert the creative an ad carries, if the platform identified one.
     *
     * `thumbnail_url` and `preview_url` are written only when the platform sent them. A creative with
     * neither is stored with both null, and the UI says «لا تتوفر معاينة» — which is true, and is the
     * whole reason those columns have always been nullable.
     *
     * @param  array{ad_sets:int,ads:int,creatives:int,skipped:int}  $counts
     */
    private function creativeFor(
        ExternalAccount $account,
        ExternalCampaign $campaign,
        mixed $creative,
        string $adExternalId,
        array &$counts,
    ): ?string {
        if (! is_array($creative) || ($creative['external_id'] ?? '') === '') {
            return null;
        }

        $row = ExternalCreative::withoutGlobalScopes()->updateOrCreate(
            [
                'project_id' => $campaign->project_id,
                'provider' => $account->provider,
                'external_creative_id' => (string) $creative['external_id'],
            ],
            [
                'tenant_id' => $campaign->tenant_id,
                'campaign_id' => $campaign->unified_campaign_id,
                'external_campaign_id' => $campaign->id,
                'name' => (string) ($creative['name'] ?? $creative['external_id']),
                'format' => (string) ($creative['format'] ?? 'image'),
                'thumbnail_url' => $creative['thumbnail_url'] ?? null,
                'preview_url' => $creative['preview_url'] ?? null,
                /*
                 * SNAP-CREATIVE-ASSETS-001 — the file itself, when the connector resolved one.
                 *
                 * These two columns existed, were fillable, were read by `CreativePresenter`, and
                 * nothing had ever written them. A connector could fetch an asset perfectly and the
                 * row would still come out empty, so the card said «this platform does not expose
                 * the creative's asset» — a claim about the provider that was really a gap here.
                 *
                 * Null-coalesced rather than omitted: a provider that sends no asset must not have
                 * a previously-stored one silently kept alive under a new sync.
                 */
                'asset_url' => $creative['asset_url'] ?? null,
                'video_url' => $creative['video_url'] ?? null,
                'asset_expires_at' => $this->time($creative['asset_expires_at'] ?? null),
                'destination_url' => $creative['destination_url'] ?? null,
                'source_type' => 'api',
                'is_demo' => false,
                'last_synced_at' => Carbon::now(),
            ],
        );

        $counts['creatives']++;

        return (string) $row->getKey();
    }

    /** A platform timestamp, or null — an unparseable one is not worth failing a whole import over. */
    private function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
