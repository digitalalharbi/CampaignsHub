<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Actions;

use App\Domains\Campaigns\Jobs\CacheCreativeCoverJob;
use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CampaignObjectiveResolver;
use App\Domains\Campaigns\Services\CoverCache;
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
                        // LEAD-DESTINATION-001 — where the results happen, as the platform spelt it.
                        'destination_type' => $row['destination_type'] ?? null,
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

        /*
         * LEAD-DESTINATION-001 — the ad sets are what state the destination, and the campaigns were
         * classified before they existed. Re-derive the objective of every campaign this sweep
         * touched so a messaging buy stops reading as a sales one on the next sweep, not the one after.
         */
        $unified = $campaigns->pluck('unified_campaign_id')->filter()->unique()->values()->all();
        if ($unified !== []) {
            $resolver = app(CampaignObjectiveResolver::class);

            UnifiedCampaign::withoutGlobalScopes()
                ->whereIn('id', $unified)
                ->each(fn (UnifiedCampaign $c) => $resolver->sync($c));
        }

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
                // CREATIVE-ACCOUNT-IDENTITY-001 — two accounts reporting one creative id are two creatives.
                'external_account_id' => $account->getKey(),
                'external_creative_id' => (string) $creative['external_id'],
            ],
            [
                'tenant_id' => $campaign->tenant_id,
                'campaign_id' => $campaign->unified_campaign_id,
                'external_campaign_id' => $campaign->id,
                'name' => (string) ($creative['name'] ?? $creative['external_id']),
                /*
                 * CONTENT-PREVIEW-SHAPES-001 — an unstated format is not an image.
                 *
                 * This read `(string) ($creative['format'] ?? 'image')`. A connector that could not
                 * map a platform's creative type deliberately emits nothing, and this turned that
                 * silence into a claim: the ad became an IMAGE, `CreativePresenter::kind()` looked
                 * for a still, found none, and told the reader «the platform exposed no asset for
                 * it» — a sentence about Snapchat that was really about a default here.
                 *
                 * Null is what a column says when nobody knows. The presenter has an honest answer
                 * for it; it had none for a lie.
                 */
                'format' => isset($creative['format']) ? (string) $creative['format'] : null,
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
                /*
                 * AD-MEDIA-RECOVERY-001 — a carousel's cards, when the connector read them.
                 *
                 * `cards` has been a column, a cast and a documented three-state contract since
                 * `CreativePresenter` was written; the presenter reads it, the shared report reads
                 * it, and NOTHING HAS EVER WRITTEN IT. So every carousel on every platform arrived
                 * as `cards_reported: false` — «the provider sent no breakdown» — which was a claim
                 * about the provider and really a gap here, and every five-card creative rendered as
                 * one picture.
                 *
                 * The three states survive the write: absent ⇒ null (no breakdown), `[]` ⇒ the
                 * provider sent an empty one, a list ⇒ the cards it sent.
                 */
                'cards' => $creative['cards'] ?? null,
                'asset_expires_at' => $this->time($creative['asset_expires_at'] ?? null),
                'destination_url' => $creative['destination_url'] ?? null,
                // OPENAI-CHAT-CARD-TEXT-001 — the copy, when the connector sent it. Only written when it
                // was sent: a provider that sends none must not blank copy another writer stored.
                ...array_filter([
                    'headline' => is_string($creative['headline'] ?? null) && $creative['headline'] !== '' ? $creative['headline'] : null,
                    'body' => is_string($creative['body'] ?? null) && $creative['body'] !== '' ? $creative['body'] : null,
                    // TIKTOK-MEDIA-001 — the file's own dimensions and length, only when the connector sent them.
                    'width' => is_int($creative['width'] ?? null) ? $creative['width'] : null,
                    'height' => is_int($creative['height'] ?? null) ? $creative['height'] : null,
                    // The column is whole seconds; TikTok states a clip's length as 9.734. Writing the float failed the WHOLE structure
                    // sync in Production (`invalid input syntax for type integer`), so no new campaign or creative arrived.
                    'duration_seconds' => is_numeric($creative['duration_seconds'] ?? null) ? (int) round((float) $creative['duration_seconds']) : null,
                ], static fn ($v) => $v !== null),
                /*
                 * CONTENT-NOTHING-TO-DRAW-WHY-001 — the body the platform sent, kept.
                 *
                 * `raw` has been a fillable, cast column since the model was written and nothing here ever
                 * wrote it, so «what did the provider send for the creatives that draw nothing» could only
                 * answer «no payload kept» — Production 2026-10-18 printed exactly that for all ten OpenAI chat
                 * cards, after the connector had started handing the payload over. A connector that sends
                 * none keeps none, and an oversized body is dropped rather than stored (a creative row is
                 * read on every card of the library).
                 */
                'raw' => $this->keptBody($creative['raw'] ?? null),
                'source_type' => 'api',
                'is_demo' => false,
                'last_synced_at' => Carbon::now(),
            ],
        );
        // TIKTOK-MEDIA-002 — take the cover into our own storage once the row holds a provider link.
        // Only through a real queue: a sync queue would fetch provider media inside the structure pass itself.
        if (config('queue.default') !== 'sync' && CoverCache::wanted($row)) {
            CacheCreativeCoverJob::dispatch((string) $row->getKey());
        }

        $counts['creatives']++;

        return (string) $row->getKey();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function keptBody(mixed $raw): ?array
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $encoded = json_encode($raw);

        return $encoded !== false && strlen($encoded) <= 16_384 ? $raw : null;
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
