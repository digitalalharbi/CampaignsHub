<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Campaigns\Actions\UpsertCreativeDailyMetrics;
use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationRawPayload;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Providers\ReportsCreativeInsights;
use App\Domains\Integrations\Providers\SnapchatConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\Actions\UpsertEntityDailyMetrics;
use App\Domains\Metrics\Enums\SyncRunStatus;
use App\Domains\Metrics\Models\EntityDailyMetric;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * SYNC-001 — the pipeline that turns a connector's insights into normalized daily metrics, and records
 * an auditable run for every attempt.
 *
 * The honesty contract this class enforces:
 *  - A connector without real credentials is NOT called. The run says so in words, and its error
 *    category stays `awaiting_credentials`, so "we never tried" is still tellable from "we tried and
 *    it broke" even though both are recorded under the one word §8 allows.
 *  - Every outcome leaves a MetricSyncRun row with its window, its four counts and its error text —
 *    see {@see SyncRunStatus}. A sync that produced nothing says WHERE the rows stopped instead of
 *    silently looking healthy.
 *  - Metrics go through the SAME UpsertDailyMetrics action the rest of the system uses, so a synced
 *    figure and a seeded figure are indistinguishable downstream and the upsert stays idempotent.
 */
final class AccountMetricsSyncer
{
    /** `integrations:sync` runs every thirty minutes (routes/console.php) — this is that, named. */
    private const SWEEP_INTERVAL_MINUTES = 30;

    public function __construct(
        private readonly AdvertisingConnectorRegistry $registry,
        private readonly UpsertDailyMetrics $upsert,
        private readonly InsightRowNormaliser $normaliser,
        private readonly AccountAssignment $assignment,
    ) {}

    /**
     * Sync one ad account's insights for a window. Always returns the recorded run.
     *
     * @param  array<string,mixed>  $meta  extra context stored on the run (e.g. ['demo' => true])
     */
    public function sync(ExternalAccount $account, Carbon $from, Carbon $to, array $meta = []): MetricSyncRun
    {
        $connector = $this->registry->get($account->provider);

        $run = new MetricSyncRun;
        $run->forceFill([
            'tenant_id' => $account->tenant_id,
            'project_id' => $this->projectIdFor($account),
            'connection_id' => $account->provider_connection_id,
            'external_account_id' => $account->id,
            'provider' => $account->provider,
            'status' => SyncRunStatus::Running->value,
            'window_start' => $from->toDateString(),
            'window_end' => $to->toDateString(),
            'attempts' => 1,
            'started_at' => Carbon::now(),
            'meta' => $meta,
        ])->save();

        /*
         * RUNTIME-100 §15 — an unassigned account is not synced, and its refusal is its own status.
         *
         * Not `failed`: nothing broke. Somebody has authorised us to SEE this account and has not
         * said which project it feeds, and the operator's next move — choose a project — is entirely
         * different from the next move for a provider error. `AccountStructureSyncer` reports the
         * same state under the same name.
         */
        if ($run->project_id === null) {
            return $this->finish(
                $run,
                SyncRunStatus::AwaitingAssignment,
                0,
                'This account is not assigned to a project yet, so nothing was fetched. Assign it to a project first.',
                $account,
            );
        }

        if ($connector === null) {
            return $this->finish($run, SyncRunStatus::Failed, 0, "No connector is registered for provider '{$account->provider}'.", $account);
        }

        /*
         * Bind the account's own connection before asking the connector anything (INTEG-OAUTH-001).
         *
         * The registry hands out ONE instance per platform for the whole process, so an unbound
         * connector has no tokens and — worse — a connector bound in place would carry one tenant's
         * connection into the next tenant's job. `withConnection()` returns a clone for exactly that
         * reason, and this is the only place in the sync path that binds one.
         */
        if ($connector instanceof ApiAdvertisingConnector) {
            $connection = ProviderConnection::withoutGlobalScopes()->find($account->provider_connection_id);

            if ($connection === null) {
                return $this->finish($run, SyncRunStatus::Failed, 0, 'The ad account has no provider connection to sync through.', $account);
            }

            $connector = $connector->withConnection($connection);
        }

        /*
         * Never pretend to call a platform we have no credentials for.
         *
         * Recorded as `failed`, because §8 gives the sync six words and this is not one of them — and
         * the sentence below carries the whole distinction anyway: nothing was fetched, and the fix is
         * an operator's, not a customer's. `last_sync_error_category` still says `awaiting_credentials`
         * so the account can be counted and routed as the setup problem it is.
         */
        if ($connector->status() === ConnectorStatus::AwaitingCredentials) {
            return $this->finish(
                $run,
                SyncRunStatus::Failed,
                0,
                'No credentials for '.$connector->label().' — nothing was fetched, and no request was made. Add credentials to enable this sync.',
                $account,
                category: 'awaiting_credentials',
            );
        }

        try {
            $result = $connector->syncInsights($account->external_id, $from->toDateString(), $to->toDateString());
        } catch (Throwable $e) {
            return $this->finish($run, SyncRunStatus::Failed, 0, $e->getMessage(), $account, $this->counts($connector), 'provider_error');
        }

        if (! $result->success) {
            return $this->finish(
                $run,
                SyncRunStatus::Failed,
                0,
                $result->message ?? 'The provider reported a failed sync.',
                $account,
                $this->counts($connector),
                'provider_error',
            );
        }

        [$upserted, $skipped] = $this->ingest($account, $result->records);

        /*
         * SNAP-CREATIVE-METRICS-001 — the same window, asked again at the creative level.
         *
         * Campaign totals answer «how is the account doing». They cannot answer «which creative is
         * working», which is what the content library exists for — and until now it had nothing to
         * show, because nobody had asked. This is a separate call to a separate table
         * (`creative_daily_metrics`), so a failure here cannot cost the campaign figures that have
         * already been ingested above.
         *
         * Guarded by the interface: a provider that reports no creative level is never asked, and
         * pays no round trip for it. Failures are swallowed deliberately — creative numbers are an
         * enrichment, and turning a healthy metrics run red because one extra call was throttled
         * would trade a working pipeline for a nicer screen.
         */
        if ($connector instanceof ReportsCreativeInsights) {
            try {
                $creative = $connector->syncCreativeInsights(
                    $account->external_id,
                    $from->toDateString(),
                    $to->toDateString(),
                );

                if ($creative->success) {
                    $written = app(UpsertCreativeDailyMetrics::class)->execute($account, $creative->records);

                    /*
                     * CONTENT-STATE-SEMANTICS-001 — «it worked and there was nothing» is an ANSWER.
                     *
                     * Recording success with a row count is what lets the Content Library say «لم
                     * يعمل خلال هذه الفترة» for a creative that genuinely did not run, instead of
                     * «لا توجد بيانات» — which is what it also says when a request failed. Those
                     * are opposite situations for an operator: one is a campaign to leave alone,
                     * the other is a pipeline to go and fix.
                     */
                    $run->forceFill([
                        'creative_status' => 'success',
                        'creative_rows' => $written['upserted'],
                        'creative_error' => null,
                        /*
                         * CONTENT-KPI-COVERAGE-002 — what the sweep received, beside what it wrote.
                         *
                         * `creative_rows` alone cannot explain a creative with no figures whose ad
                         * demonstrably ran: «the platform returned nothing for it» and «the platform
                         * named it and this project could not resolve the id» produce the identical
                         * empty table, and they are fixed in different places.
                         *
                         * Merged into the existing meta rather than replacing it — the media sweep
                         * and the entity sweep write their own keys here.
                         */
                        'meta' => [
                            ...(array) ($run->meta ?? []),
                            'creative_rows_received' => $written['rows_received'],
                            'creative_ids_received' => $written['ids_received'],
                            'creative_ids_mapped' => $written['ids_mapped'],
                            'creative_ids_unmapped' => $written['ids_unmapped'],
                            'creative_ids_ambiguous' => $written['ambiguous'],
                            'creative_rows_skipped' => $written['skipped'],
                            'creative_unmapped_sample' => $written['unmapped_sample'],
                        ],
                    ])->save();
                } else {
                    $run->forceFill([
                        'creative_status' => 'failed',
                        'creative_rows' => 0,
                        // The provider's own words. «Rate limited» and «this account reports no
                        // creative stats» both arrive here and mean entirely different things.
                        'creative_error' => Str::limit((string) $creative->message, 480),
                    ])->save();
                }
            } catch (Throwable $e) {
                /*
                 * Still swallowed for the RUN's verdict — creative numbers are an enrichment, and
                 * turning a healthy metrics run red because one extra call was throttled would
                 * trade a working pipeline for a nicer screen. But it is no longer swallowed for
                 * the READER: the failure is now written down where the Content Library can find
                 * it and say what actually happened.
                 */
                $run->forceFill([
                    'creative_status' => 'failed',
                    'creative_rows' => 0,
                    'creative_error' => Str::limit($e->getMessage(), 480),
                ])->save();
            }
        } else {
            /*
             * Never asked, and that is not a failure — it is a fact about the provider.
             *
             * Only Snapchat implements `ReportsCreativeInsights`. A TikTok creative showing «no
             * data» implies numbers that failed to arrive; «هذه المنصة لا توفر بيانات أداء لكل
             * محتوى» is the truth, and it is a different sentence.
             */
            $run->forceFill([
                'creative_status' => 'unsupported',
                'creative_rows' => 0,
                'creative_error' => null,
            ])->save();
        }

        /*
         * METRICS-BACKBONE-001 — the two rungs between a campaign and a creative.
         *
         * `entity_daily_metrics`, `UpsertEntityDailyMetrics`, `fetchEntityInsights()` and
         * `EntityMetricsAggregator` all existed and NOTHING CALLED THEM. The table stayed empty in
         * production, so the Ad Set and Ads tabs had nothing to render and the drill-down stopped at
         * the campaign. A backbone nobody calls is not a backbone.
         *
         * The shape is forced by the API: `breakdown=adsquad` lives on the CAMPAIGN stats endpoint
         * and `breakdown=ad` on the AD SQUAD endpoint, so each rung is swept from its own parents —
         * campaigns give ad squads, ad squads give ads.
         *
         * Same guarantees as the creative call above: a separate table, so a failure here cannot
         * cost the campaign figures already ingested, and failures do not turn a healthy run red.
         */
        if ($connector instanceof SnapchatConnector) {
            $this->syncEntityGrains($connector, $account, $run, $from, $to);
        }

        /*
         * Keep what the platform said, beside what we made of it (INTEG-RAW-001).
         *
         * Written after the ingest so `normalised_rows` can record how many metrics came OUT of this
         * payload — a body with four hundred rows that produced zero metrics is the signature of a
         * mapping bug, and that comparison is invisible from either half alone.
         */
        if ($connector instanceof ApiAdvertisingConnector) {
            $this->retainRaw($account, $run, $connector->takeRawResponses(), $from, $to, $upserted);
        }

        $parsed = count($result->records);
        $mapped = $parsed - $skipped;

        $counts = [
            'provider_raw_rows' => $this->counts($connector)['provider_raw_rows'] ?? $parsed,
            'parsed_rows' => $parsed,
            'mapped_campaign_rows' => $mapped,
        ];

        /*
         * INTEG-RUNTIME §8 — where the rows stopped decides the word, and the words no longer overlap.
         *
         * The order matters. `no_data` is asked FIRST, because a window the provider had nothing for
         * is not a partial anything and must never be dressed as one: it is the ordinary answer for a
         * paused campaign, a weekend, an account that had not spent yet. It is not an error and no
         * screen may colour it as one.
         *
         * `partial_mapping` is the one that IS ours: the provider answered, and rows named campaigns
         * we have not discovered. On a first sync that usually means structure has not landed yet; on
         * a later one it means discovery is behind. Either way the rows are missing from the client's
         * report and somebody has to act.
         *
         * And `success` requires metrics to have actually landed. `$upserted === 0` with rows mapped
         * means every figure in those rows was one this pipeline does not carry — which is a real
         * gap, reported as `partial_mapping` rather than as a green tick over an empty dashboard.
         */
        if ($parsed === 0) {
            return $this->finish(
                $run,
                SyncRunStatus::NoData,
                0,
                null,
                $account,
                $counts,
            );
        }

        if ($skipped > 0) {
            return $this->finish(
                $run,
                SyncRunStatus::PartialMapping,
                $upserted,
                "{$skipped} of {$parsed} row(s) named a campaign that has not been discovered yet, so they were not stored.",
                $account,
                $counts,
                'unmapped_rows',
            );
        }

        if ($upserted === 0) {
            return $this->finish(
                $run,
                SyncRunStatus::PartialMapping,
                0,
                "{$parsed} row(s) were matched to a campaign but carried no metric this pipeline stores.",
                $account,
                $counts,
                'unmapped_rows',
            );
        }

        return $this->finish($run, SyncRunStatus::Success, $upserted, null, $account, $counts);
    }

    /**
     * What the platform handed the connector, when the connector is one that counts.
     *
     * The sandbox connector is not an `ApiAdvertisingConnector` and measures nothing, so its runs
     * record NULL rather than a zero it never observed.
     *
     * @return array<string,int|null>
     */
    private function counts(object $connector): array
    {
        return $connector instanceof ApiAdvertisingConnector
            ? ['provider_raw_rows' => $connector->takeRawInsightRows()]
            : [];
    }

    /**
     * Every metric a connector may report and this pipeline will carry (PIPELINE-METRICS-001).
     *
     * Read from `MetricsAggregator::readKeys()` rather than written out here, and that is the whole
     * point. This used to be a literal list of seven — `spend, impressions, clicks, conversions,
     * revenue, reach, video_views` — while the aggregator read eighteen. So a connector could map
     * `add_to_cart` perfectly, from the platform's own correct field, and the figure was DROPPED on
     * this line before it ever reached storage. Nothing failed, nothing was logged: the funnel simply
     * had no add-to-cart stage, on every platform, forever.
     *
     * Two lists describing one thing is how that happens, so there is now one. A key the engine reads
     * is a key the syncer carries, by construction.
     *
     * DERIVED metrics are deliberately absent and must stay absent. `readKeys()` returns only the
     * additive keys — the ones `metric_definitions.is_additive` marks true — because `frequency`,
     * `roas`, `ctr`, `cpa` and the rest are computed FROM sums and are meaningless when summed
     * themselves. A stored daily `frequency` added across thirty days is not a frequency; it is a
     * number with no referent. They are computed at read time, and a zero denominator yields null
     * rather than 0.
     *
     * @return list<string>
     */
    private static function carriedKeys(): array
    {
        return MetricsAggregator::readKeys();
    }

    /**
     * METRICS-BACKBONE-001 — ad-squad then ad metrics, each swept from its own parents.
     *
     * Runs after the campaign ingest and writes only to `entity_daily_metrics`, so nothing here can
     * disturb a figure any existing surface already reads.
     *
     * Every failure is contained. `fetchEntityInsights()` already isolates one parent's failure from
     * the rest; this wraps the whole grain so a provider change cannot cost the metrics run.
     */
    private function syncEntityGrains(
        SnapchatConnector $connector,
        ExternalAccount $account,
        MetricSyncRun $run,
        Carbon $from,
        Carbon $to,
    ): void {
        // The campaigns this account owns — the parents of the ad-squad grain.
        $campaigns = ExternalCampaign::withoutGlobalScopes()
            ->where('external_account_id', $account->id)
            ->pluck('external_id', 'id');

        $squadRows = $this->grain(
            fn (): array => $connector->syncEntityInsights(
                $account->external_id, 'campaigns', 'adsquad',
                $campaigns->values()->all(), $from->toDateString(), $to->toDateString(),
            )->records,
        );

        /*
         * The sweep resolves the provider's ids against what the structure sync already discovered.
         * An entity we have not discovered is skipped by the upsert rather than invented — the
         * structure sweep owns identity and this owns numbers.
         */
        $squads = ExternalAdSet::withoutGlobalScopes()
            ->whereIn('external_campaign_id', $campaigns->keys())
            ->get(['id', 'external_id', 'project_id', 'tenant_id', 'external_campaign_id']);

        $knownSquads = [];
        foreach ($squads as $squad) {
            $knownSquads[(string) $squad->external_id] = [
                'id' => (string) $squad->getKey(),
                'project_id' => (string) $squad->project_id,
                'tenant_id' => (string) $squad->tenant_id,
                'campaign_id' => $squad->external_campaign_id === null ? null : (string) $squad->external_campaign_id,
                'ad_set_id' => null,
            ];
        }

        $squadResult = app(UpsertEntityDailyMetrics::class)->execute(
            $account, EntityDailyMetric::AD_SET, $squadRows, $knownSquads, (string) $run->getKey(),
        );

        $adRows = $this->grain(
            /*
             * Ads come from the CAMPAIGN endpoint, not the ad-squad one.
             *
             * The current API documents both `breakdown=ad` and `breakdown=adsquad` on
             * `campaigns/{id}/stats`; the ad-squad endpoint documents no breakdown at all. Sweeping
             * ads from their squads therefore asked 187 times for something that endpoint does not
             * offer — which is exactly the shape of «every call refused, table silently empty».
             *
             * Asking campaigns for both grains is also 89 calls instead of 187 + 89.
             */
            fn (): array => $connector->syncEntityInsights(
                $account->external_id, 'campaigns', 'ad',
                $campaigns->values()->all(),
                $from->toDateString(), $to->toDateString(),
            )->records,
        );

        $ads = ExternalAd::withoutGlobalScopes()
            ->whereIn('external_ad_set_id', $squads->modelKeys())
            ->get(['id', 'external_id', 'project_id', 'tenant_id', 'external_campaign_id', 'external_ad_set_id']);

        $knownAds = [];
        foreach ($ads as $ad) {
            $knownAds[(string) $ad->external_id] = [
                'id' => (string) $ad->getKey(),
                'project_id' => (string) $ad->project_id,
                'tenant_id' => (string) $ad->tenant_id,
                'campaign_id' => $ad->external_campaign_id === null ? null : (string) $ad->external_campaign_id,
                'ad_set_id' => $ad->external_ad_set_id === null ? null : (string) $ad->external_ad_set_id,
            ];
        }

        $adResult = app(UpsertEntityDailyMetrics::class)->execute(
            $account, EntityDailyMetric::AD, $adRows, $knownAds, (string) $run->getKey(),
        );

        /*
         * Record WHY the grains are empty, when they are.
         *
         * An empty `entity_daily_metrics` has two completely different causes — no sweep has run
         * since the ingest was wired, or the platform refused every call — and a row count cannot
         * tell them apart. The run already carries a `meta` column; putting the first refusal there
         * costs no migration and makes the next diagnostic explain itself instead of guessing.
         */
        $run->forceFill([
            'meta' => [
                ...(array) ($run->meta ?? []),
                'entity_ad_sets' => $squadResult['upserted'],
                'entity_ads' => $adResult['upserted'],
                'entity_failure' => $connector->lastEntityFailure(),
            ],
        ])->save();
    }

    /**
     * One grain's fetch, with its failure contained.
     *
     * @param  callable(): list<array<string,mixed>>  $fetch
     * @return list<array<string,mixed>>
     */
    private function grain(callable $fetch): array
    {
        try {
            return $fetch();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Map provider insight rows onto normalized metrics.
     *
     * @param  array<int,array<string,mixed>>  $records
     * @return array{0:int,1:int} [upserted metric rows, skipped records]
     */
    private function ingest(ExternalAccount $account, array $records): array
    {
        // Mapping lives in its own class (FX-001): this method is pipeline plumbing, and what a row
        // MEANS — including which currency its money is in — is a separate question with its own tests.
        [$metrics, $skipped] = $this->normaliser->normalise($account, $records, self::carriedKeys());

        if ($metrics !== []) {
            $this->upsert->handle($metrics);
        }

        return [count($metrics), $skipped];
    }

    /**
     * Store the platform's own bodies for this run.
     *
     * One row per response — not one concatenated blob — because a window that needed three paginated
     * calls is three answers, and a dispute about one of them should not require unpicking the others.
     *
     * @param  list<array<string,mixed>>  $payloads
     */
    private function retainRaw(
        ExternalAccount $account,
        MetricSyncRun $run,
        array $payloads,
        Carbon $from,
        Carbon $to,
        int $normalisedRows,
    ): void {
        foreach ($payloads as $index => $payload) {
            IntegrationRawPayload::withoutGlobalScopes()->create([
                'tenant_id' => $account->tenant_id,
                'external_account_id' => $account->id,
                'sync_run_id' => $run->getKey(),
                'provider' => $account->provider,
                'resource' => 'insights',
                'window_start' => $from->toDateString(),
                'window_end' => $to->toDateString(),
                'payload' => $payload,
                // The count belongs to the run, so it is attributed to the first payload rather than
                // divided by a guess about which call produced which row.
                'normalised_rows' => $index === 0 ? $normalisedRows : 0,
                'fetched_at' => Carbon::now(),
            ]);
        }
    }

    /**
     * @param  array<string,int|null>  $counts  whichever of the four numbers this outcome measured
     * @param  string|null  $category  why it did not work, as something countable
     */
    private function finish(
        MetricSyncRun $run,
        SyncRunStatus $status,
        int $upserted,
        ?string $error,
        ?ExternalAccount $account = null,
        array $counts = [],
        ?string $category = null,
    ): MetricSyncRun {
        $run->forceFill([
            'status' => $status->value,
            'metrics_upserted' => $upserted,
            // Absent stays absent: a refusal that never reached the provider measured nothing, and
            // writing 0 would claim a count that was never taken.
            'provider_raw_rows' => $counts['provider_raw_rows'] ?? null,
            'parsed_rows' => $counts['parsed_rows'] ?? null,
            'mapped_campaign_rows' => $counts['mapped_campaign_rows'] ?? null,
            'finished_at' => Carbon::now(),
            'error' => $error,
        ])->save();

        if ($account !== null) {
            $this->checkpoint($account, $status, $category);
        }

        return $run;
    }

    /**
     * Which project this run belongs to — METRICS-RUN-PROJECT-001.
     *
     * ## The half of ASSIGN-PROJECT-001 that survived its own fix
     *
     * `AccountStructureSyncer` had exactly this method and exactly this bug, and it was fixed. This
     * copy was not, and it ended:
     *
     * ```php
     * return Project::withoutGlobalScopes()->where('tenant_id', …)->orderBy('created_at')->value('id');
     * ```
     *
     * `MetricSyncRun` carries `BelongsToProject`, and `SyncRunController` lists runs project-scoped.
     * So a metrics run for an account assigned to client B — doing exactly what it was told — was
     * filed under client A's oldest project for as long as it had no campaigns yet, which is
     * precisely the window a FIRST sync runs in. Client A's operator then read sync history naming
     * client B's provider, account and row counts, and `DataFreshnessService` computed A's freshness
     * from B's runs.
     *
     * Two copies of one rule, one of them corrected. That is what let it survive a review of the fix,
     * and is why the answer now comes from `AccountAssignment` — the single place that knows.
     *
     * There is no second answer, deliberately. An «existing campaign wins» fallback reads as harmless
     * — it only keeps work where it already is — but it is a second route by which data can enter a
     * project nobody assigned it to, and it can only fire for an account the worker has already
     * refused. One rule, one source, and nothing that was filed before is moved or deleted.
     */
    private function projectIdFor(ExternalAccount $account): ?string
    {
        return $this->assignment->projectIdFor($account);
    }

    /**
     * RUNTIME-100 §30 — write the account's checkpoint from the outcome, once, in one place.
     *
     * `last_synced_at` used to be set inside `retainRaw()`, which is a method about keeping the
     * provider's raw bodies. Two consequences, both wrong and both quiet: a connector that is not an
     * `ApiAdvertisingConnector` synced successfully and the account still read «never synced»; and
     * the stamp was written BEFORE `finish()` decided the status, so a run that ended «the provider
     * returned no insight rows» had already claimed a sync.
     *
     * A checkpoint belongs to the outcome, so it is written where the outcome is decided.
     */
    private function checkpoint(ExternalAccount $account, SyncRunStatus $status, ?string $category): void
    {
        /*
         * `no_data` counts as a successful reach.
         *
         * We asked, the provider answered, and the answer was «nothing happened». That is a completed
         * conversation, and stamping `last_synced_at` for it is what stops a quiet account drifting
         * into «never synced» and then into a staleness alert nobody should receive.
         */
        $succeeded = in_array($status, [SyncRunStatus::Success, SyncRunStatus::NoData, SyncRunStatus::PartialMapping], true);

        $account->forceFill([
            // Every outcome is an attempt, including the refusals. «We tried and it failed» and «we
            // never tried» were the same absent timestamp until this line existed.
            'last_sync_attempt_at' => Carbon::now(),
            'last_synced_at' => $succeeded ? Carbon::now() : $account->last_synced_at,
            'last_sync_error_category' => $this->errorCategory($status, $category),
            'next_sync_at' => Carbon::now()->addMinutes(self::SWEEP_INTERVAL_MINUTES),
        ])->save();
    }

    /**
     * WHY it did not work, as a category — because the category is what decides who acts.
     *
     * An operator adds credentials; a customer re-authorises; nobody at all acts on a provider having
     * a bad minute. A free-text message cannot be grouped, counted or routed, and every screen that
     * wanted to say «1 حساب يحتاج انتباه» had nothing to count.
     */
    private function errorCategory(SyncRunStatus $status, ?string $category): ?string
    {
        return match ($status) {
            SyncRunStatus::AwaitingAssignment => 'awaiting_assignment',
            // `awaiting_credentials` and `provider_error` are both `failed` runs and are NOT the same
            // problem: one is an operator adding keys, the other is a platform having a bad minute.
            SyncRunStatus::Failed => $category ?? 'provider_error',
            SyncRunStatus::PartialMapping => $category ?? 'unmapped_rows',
            /*
             * `success` and `no_data` carry NO category, and `no_data` is the one worth stating.
             *
             * A window the provider genuinely had no rows for is not a fault and nobody should be
             * paged about it. Marking an account as needing attention because it did not spend last
             * Tuesday would fill the attention count with noise and train people to ignore it.
             */
            default => null,
        };
    }
}
