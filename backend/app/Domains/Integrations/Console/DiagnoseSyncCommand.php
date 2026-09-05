<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Campaigns\Actions\StampHistoricalUnlinks;
use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\Campaigns\Services\PlatformObjectiveMap;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationRawPayload;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Metrics\Services\InsightPayloadRows;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * INTEG-RUNTIME §7 — «why is the answer 0?», answered with numbers instead of a theory.
 *
 * ## What this is for
 *
 * A live Snapchat connection reached 309 accounts and produced no metrics. `metrics_upserted = 0` is
 * where every investigation stopped, because that one figure is equally true of four different
 * situations with four different fixes:
 *
 *   the provider sent nothing           → NO_DATA. Not a defect. Nothing to fix.
 *   the provider sent rows, we parsed 0 → a connector defect, in the parse.
 *   we parsed rows, mapped 0            → structure was never discovered, or discovery is behind.
 *   we mapped rows, stored 0            → the rows carried no metric this pipeline keeps.
 *
 * This prints the four counts side by side for each of an account's recent runs, so which of those
 * four it is stops being a matter of opinion.
 *
 * ## Read-only, and that is a promise not a habit
 *
 * It calls no provider, refreshes no token, queues no job and writes no row. Everything it reports
 * comes from what is already stored — the run log and the provider's own retained bodies. It is safe
 * to point at production precisely because there is nothing it can break there.
 *
 * Runs whose counters are NULL predate the instrumentation; for those the numbers are recovered from
 * the retained payload by {@see InsightPayloadRows} and labelled as reconstructed, never presented as
 * though they had been measured at the time.
 */
final class DiagnoseSyncCommand extends Command
{
    protected $signature = 'integrations:diagnose
        {--provider= : Limit to one provider key, e.g. snapchat}
        {--account= : Limit to one external account id (ours) or the provider\'s own id}
        {--runs=5 : How many recent runs to show per account}
        {--accounts=10 : How many accounts to show}
        {--linked : Only accounts with an ACTIVE binding to a project}
        {--payload : Print the provider\'s own last insights body, and the campaigns it could match}
        {--downstream : What the assigned PROJECT now holds — the tables every surface reads}
        {--hierarchy : Campaigns → ad squads → ads → creatives, with the orphans at each level}';

    protected $description = 'Read-only: where a sync\'s rows stopped, per account, with the four counts.';

    public function handle(): int
    {
        $provider = $this->stringOption('provider');
        $accountRef = $this->stringOption('account');
        $runLimit = max(1, (int) $this->option('runs'));
        $accountLimit = max(1, (int) $this->option('accounts'));

        $accounts = ExternalAccount::withoutGlobalScopes()
            ->when($provider !== null, fn ($q) => $q->where('provider', $provider))
            ->when($accountRef !== null, fn ($q) => $q->where(
                fn ($w) => $w->where('id', $accountRef)->orWhere('external_id', $accountRef),
            ))
            /*
             * `--linked` exists because 309 discovered accounts is 308 rows of «binding=NONE» around
             * the one row anybody is asking about, and a diagnosis nobody can find is not a diagnosis.
             */
            ->when((bool) $this->option('linked'), fn ($q) => $q->whereIn(
                'id',
                ProjectIntegrationBinding::withoutGlobalScopes()->where('is_active', true)->select('external_account_id'),
            ))
            ->orderByDesc('last_sync_attempt_at')
            ->orderByDesc('discovered_at')
            ->limit($accountLimit)
            ->get();

        if ($accounts->isEmpty()) {
            $this->warn('No external account matches that filter.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line(str_repeat('=', 78));
        $this->line('  INTEGRATIONS DIAGNOSIS — read-only. No provider was called.');
        $this->line(str_repeat('=', 78));

        $this->reportProviderTotals($provider);

        foreach ($accounts as $account) {
            $this->reportAccount($account, $runLimit);
        }

        return self::SUCCESS;
    }

    /** The shape of the estate, before any single account: how much was discovered, how much assigned. */
    private function reportProviderTotals(?string $provider): void
    {
        $discovered = ExternalAccount::withoutGlobalScopes()
            ->when($provider !== null, fn ($q) => $q->where('provider', $provider))
            ->count();

        $assigned = ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('is_active', true)
            ->whereIn(
                'external_account_id',
                ExternalAccount::withoutGlobalScopes()
                    ->when($provider !== null, fn ($q) => $q->where('provider', $provider))
                    ->select('id'),
            )
            ->count();

        $this->line('');
        $this->line(sprintf(
            '  Estate%s: %d account(s) discovered, %d with an ACTIVE binding to a project.',
            $provider === null ? '' : " [{$provider}]",
            $discovered,
            $assigned,
        ));
    }

    private function reportAccount(ExternalAccount $account, int $runLimit): void
    {
        $binding = ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->where('is_active', true)
            ->first();

        $projectName = $binding === null
            ? null
            : Project::withoutGlobalScopes()->whereKey($binding->project_id)->value('name');

        $connection = ProviderConnection::withoutGlobalScopes()->find($account->provider_connection_id);

        $campaigns = ExternalCampaign::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->count();

        $this->line('');
        $this->line(str_repeat('-', 78));
        $this->line(sprintf('  %s  [%s]', $account->name ?: '(unnamed)', $account->provider));
        $this->line(sprintf('  ours=%s  provider=%s', $account->getKey(), $account->external_id));
        $this->line(sprintf(
            '  connection=%s  timezone=%s  currency=%s',
            $connection === null ? 'MISSING' : $connection->status,
            $account->timezone ?? 'NOT CAPTURED',
            $account->currency ?? 'NOT CAPTURED',
        ));
        $this->line(sprintf(
            '  binding=%s  campaigns discovered=%d',
            $binding === null ? 'NONE — nothing will sync' : "ACTIVE → {$projectName}",
            $campaigns,
        ));
        $this->line(str_repeat('-', 78));

        $runs = MetricSyncRun::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->orderByDesc('started_at')
            ->limit($runLimit)
            ->get();

        /*
         * A missing METRICS run is not a reason to stop reporting — SNAP-STRUCTURE-RETRY-001 §2.
         *
         * This used to `return` here, so an account with no metrics run said one line and then
         * nothing: no structure runs, no hierarchy, no downstream, however much of all three it
         * actually held. Structure and metrics are two pipelines on two clocks, and the whole point
         * of this command is that they are reported separately. Silence about one of them because
         * the other has not run yet is the same failure in miniature as the one being diagnosed.
         */
        if ($runs->isEmpty()) {
            $this->line('  No metrics run has ever been recorded for this account. Structure is reported below regardless.');
        } else {
            $this->table(
                ['started', 'window', 'status', 'raw', 'parsed', 'mapped', 'stored', 'source'],
                $runs->map(fn (MetricSyncRun $run) => $this->runRow($run, $account))->all(),
            );

            foreach ($runs as $run) {
                if (($run->error ?? '') !== '') {
                    $this->line(sprintf('  %s → %s', $run->started_at?->toDateTimeString() ?? '?', $run->error));
                }
            }
        }

        $this->reportStructureRuns($account);

        if ((bool) $this->option('payload')) {
            $this->reportPayload($account, $runs->first());
        }

        if ((bool) $this->option('hierarchy')) {
            $this->reportHierarchy($account);
        }

        if ((bool) $this->option('downstream')) {
            $this->reportDownstream($account, $binding?->project_id === null ? null : (string) $binding->project_id, $projectName);
        }
    }

    /**
     * SNAP-STRUCTURE-RETRY-001 §2 — the four levels, counted, and whether each row was PLACED.
     *
     * ## Why counts alone are not an answer
     *
     * A sweep reporting «11,686 records» says nothing about shape. Every one of those rows could be a
     * campaign, or every ad could be filed under nothing, and the total would read the same. The
     * counts say what was discovered; placement says whether any screen can reach it, because most of
     * them walk the hierarchy downwards from the campaign.
     *
     * ## Where the orphans actually are, which is not where you would look for them
     *
     * `external_ad_sets.external_campaign_id` and `external_ads.external_campaign_id` are NOT NULL
     * with cascading foreign keys. **An orphaned ad squad or ad cannot be stored at all** — a row
     * naming a parent the sweep has not discovered is rejected at import, counted as `skipped`, and
     * turns the run `partial_mapping`. So a column headed «orphan ad squads» would print 0 for ever,
     * whatever happened, which is precisely the kind of reassuring number this whole ticket is about.
     * The real figure lives in the run, and is shown there.
     *
     * Two nullable parents remain, and they are counted because they can genuinely happen:
     *
     * - an ad with no ad squad — CORRECT on LinkedIn and Google, where ads hang off the campaign,
     *   and wrong on Snapchat, where `ad_squad_id` is how an ad is placed at all. One number cannot
     *   be a defect on one platform and normal on another, so it is stated and left to be read.
     * - a creative with no ad — nothing on any screen can reach it.
     *
     * Scoped to THIS account's campaigns throughout. A tenant-wide count would fold in every other
     * connection and report a healthy hierarchy for an account that has none.
     */
    private function reportHierarchy(ExternalAccount $account): void
    {
        $campaignIds = ExternalCampaign::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->pluck('id');

        $this->line('');
        $this->line('  HIERARCHY — what the structure sweep discovered, and whether it was placed');

        if ($campaignIds->isEmpty()) {
            $this->line('  No campaigns discovered for this account, so there is no hierarchy to count.');

            return;
        }

        $adSetIds = ExternalAdSet::withoutGlobalScopes()
            ->whereIn('external_campaign_id', $campaignIds)
            ->pluck('id');

        $adIds = ExternalAd::withoutGlobalScopes()
            ->whereIn('external_campaign_id', $campaignIds)
            ->pluck('id');

        $creatives = ExternalCreative::withoutGlobalScopes()
            ->whereIn('external_campaign_id', $campaignIds);

        $this->table(
            ['level', 'count'],
            [
                ['campaigns', $campaignIds->count()],
                ['ad_squads', $adSetIds->count()],
                ['ads', $adIds->count()],
                ['creatives', (clone $creatives)->count()],
            ],
        );

        $adsWithoutSquad = ExternalAd::withoutGlobalScopes()
            ->whereIn('id', $adIds)
            ->whereNull('external_ad_set_id')
            ->count();

        /*
         * CREATIVE-AD-RELATION-001 — measured from the CANONICAL relation, never from
         * `external_creatives.external_ad_id`.
         *
         * That column is rewritten by `creativeFor()` on every upsert, so it names whichever ad was
         * imported last. `whereNull('external_ad_id')` therefore answered «has this creative ever
         * been touched by an import», not «is this creative reachable from an ad» — a question it
         * looks exactly like it is answering. `external_ads.creative_id` is the relation, so these
         * three numbers are read from the ads.
         */
        $adsWithoutCreative = ExternalAd::withoutGlobalScopes()
            ->whereIn('id', $adIds)
            ->whereNull('creative_id')
            ->count();

        $referencedCreativeIds = ExternalAd::withoutGlobalScopes()
            ->whereIn('id', $adIds)
            ->whereNotNull('creative_id')
            ->distinct()
            ->pluck('creative_id');

        $creativesWithNoAd = (clone $creatives)
            ->whereNotIn('id', $referencedCreativeIds->all())
            ->count();

        $this->line("  ads with no ad squad         : {$adsWithoutSquad}"
            .'   (correct on LinkedIn and Google, where ads hang off the campaign; on Snapchat an ad'
            .' is placed BY its squad, so anything above 0 here is a defect)');
        $this->line("  ads with no creative         : {$adsWithoutCreative}"
            .'   (reported, not judged — see below)');
        $this->line('  creatives referenced by ads   : '.$referencedCreativeIds->count()
            .'   (distinct `external_ads.creative_id` — the canonical relation)');
        $this->line("  creatives referenced by no ad: {$creativesWithNoAd}"
            .'   (unreachable from any screen that walks the hierarchy downwards)');

        /*
         * SANDBOX-PROD-001 / SNAP-AD-STATS-ROUTE-001 — how many of these parents are SANDBOX rows.
         *
         * Production recorded «Snapchat Marketing API could not return ad stats: Request URL can not
         * be correctly processed [path: /v1/campaigns/sbx-cmp-1/stats]» on the live bound account.
         * The path is well-formed — the blank-id defect this route already fixed is not what happened
         * — and `sbx-cmp-1` is a SANDBOX campaign id from `SandboxAdvertisingConnector`, reaching the
         * live Snapchat API. The refusal is Snapchat being right.
         *
         * `AccountMetricsSyncer::syncEntityGrains()` plucks EVERY `external_id` on the account and
         * hands the list to the connector, so any sandbox row stored under a live account becomes a
         * live request on every metrics sweep — forty-eight times a day, each one an unexplained
         * refusal in the run log.
         *
         * What is NOT yet known is how a sandbox row came to sit under a live account, and that is
         * the whole reason this counts rather than filters. A filter would silence the symptom and
         * leave the rows there; this says how many there are and on which account, so the fix can
         * name the cause. Counted from the sandbox connector's own marker rather than from an id
         * prefix — a prefix is a guess about a string, a marker is what the writer wrote.
         */
        $sandboxCampaigns = ExternalCampaign::withoutGlobalScopes()
            ->where('external_account_id', $account->id)
            ->whereJsonContains('raw->sandbox', true)
            ->count();

        $this->line("  SANDBOX campaigns on this account: {$sandboxCampaigns}"
            .'   (must be 0 on a live connection — each one becomes a live /stats request that the'
            .' provider is right to refuse)');

        foreach (['campaigns' => $campaignIds->count(), 'ad_squads' => $adSetIds->count(),
            'ads' => $adIds->count(), 'creatives' => (clone $creatives)->count()] as $level => $count) {
            if ($count === 0) {
                $this->warn("  {$level} = 0. If the provider returned rows at this level, that is a defect, "
                    .'not a quiet account — read the body with --payload before accepting it.');
            }
        }

        if ($creativesWithNoAd > 0) {
            $this->warn("  {$creativesWithNoAd} creative(s) are referenced by no ad at all.");
        }

        /*
         * «ads with no creative» is REPORTED and not called a defect — for any provider.
         *
         * The first draft warned on every provider except Google Ads and LinkedIn, reasoning that the
         * others emit a `creative` key so one must be expected. That does not follow: our adapters
         * emitting AT MOST one creative per ad row says what our code can produce, and says nothing
         * about whether the platform requires an ad to have one. An ad in review, a deleted creative,
         * a draft — each is a number, and none of them is proven to be a fault here.
         *
         * The threshold for warning is a verified platform contract, not an inference from our own
         * adapter's shape. Until one is read, the number stands on its own.
         */

        /*
         * SNAP-CREATIVE-METRICS-001 — are there creative-level NUMBERS, not just creative rows?
         *
         * The hierarchy above counts entities. It said 1,451 creatives while every one of them
         * showed «لا توجد بيانات», because `breakdown` was campaign-only and no creative-level row
         * had ever been fetched. Counting the entities could not tell those two states apart; this
         * can.
         */
        $creativeIds = (clone $creatives)->pluck('id');

        $metricRows = $creativeIds->isEmpty() ? 0 : (int) DB::table('creative_daily_metrics')
            ->whereIn('creative_id', $creativeIds->all())
            ->count();

        $withMetrics = $creativeIds->isEmpty() ? 0 : (int) DB::table('creative_daily_metrics')
            ->whereIn('creative_id', $creativeIds->all())
            ->distinct()
            ->count('creative_id');

        $latest = $creativeIds->isEmpty() ? null : DB::table('creative_daily_metrics')
            ->whereIn('creative_id', $creativeIds->all())
            ->max('metric_date');

        /*
         * FX-WITHHELD-UI-001 — what the DASHBOARD's aggregator computes, not what the table holds.
         *
         * The rows below are read straight from `daily_metrics`. The dashboard does not read them
         * that way: it calls `MetricsAggregator::totals()`, and the card decides between «0» and the
         * real figure from four fields that aggregator produces. Printing the table's own totals
         * proved the data existed while telling us nothing about what the screen would do with it.
         */
        $projectId = ExternalCampaign::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->whereNotNull('project_id')
            ->value('project_id');

        if ($projectId === null) {
            return;
        }

        /*
         * Scoped the way the CONTROLLER scopes it — `forProjects()`, not just the context.
         *
         * `base()` filters on `$this->projectIds`, a property set by `forProjects()`. Setting only
         * `ProjectContext` leaves it null, which skips the project filter entirely and aggregates
         * across every project — a different question from the one the dashboard asks, and the first
         * version of this diagnostic asked it without noticing.
         */
        /*
         * DIAGNOSE-TENANT-SCOPE-001 — the TENANT, which a console command does not have.
         *
         * `DailyMetric` uses `BelongsToTenant`, so every Eloquent read of it carries a global tenant
         * scope. A request has a tenant; `artisan` does not. So this block asked the aggregator the
         * dashboard's question with no tenant in context, the scope matched nothing, and it printed
         *
         *     impressions (control)  : 0
         *     spend                  : 0
         *     live rows  : 0   demo rows  : 0
         *
         * for a project that holds 1,848 `daily_metrics` rows dated to today — a figure the
         * `--downstream` block on the same run prints correctly, because it counts without the
         * scope.
         *
         * Read plainly, that said «the production dashboard shows zero». It does not. The dashboard
         * runs inside an authenticated request where the scope resolves, and this diagnostic was
         * about to accuse a healthy surface — the third time this instrument has produced a false
         * negative by reading through a blind spot of its own.
         *
         * The tenant comes from the ACCOUNT being diagnosed, which is the same tenant the request
         * would carry, and is forgotten again beside the project context.
         */
        app(TenantContext::class)->setTenantId((string) $account->tenant_id);
        $agg = app(MetricsAggregator::class)->forProjects([(string) $projectId]);
        app(ProjectContext::class)->setProjectId((string) $projectId);
        $totals = $agg->totals(Carbon::now()->subDays(29)->startOfDay(), Carbon::now()->endOfDay());

        $this->line('');
        $this->line('  WHAT THE DASHBOARD CARD READS (MetricsAggregator::totals, last 30 days)');
        /*
         * `impressions` is the CONTROL. Without it, a row of zeros cannot distinguish «the money is
         * missing» from «this query sees nothing at all», and the first run of this diagnostic
         * printed zeros that could have meant either.
         */
        $this->line('  impressions (control)  : '.($totals['impressions'] ?? '—'));
        $this->line('  spend                  : '.($totals['spend'] ?? '—'));
        $this->line('  spend_withheld_rows    : '.($totals['spend_withheld_rows'] ?? '—'));
        $this->line('  spend_original         : '.($totals['spend_original'] ?? '—'));
        $this->line('  money_original_currency: '.($totals['money_original_currency'] ?? 'NULL'));
        $this->line('  money_original_currencies: '.($totals['money_original_currencies'] ?? '—'));

        $shows = ((int) ($totals['spend_withheld_rows'] ?? 0)) > 0
            && ((float) ($totals['spend_original'] ?? 0)) > 0
            && is_string($totals['money_original_currency'] ?? null)
            && ((int) ($totals['money_original_currencies'] ?? 0)) === 1;

        $this->line('  → the card will show: '.($shows
            ? number_format((float) $totals['spend_original'], 2).' '.$totals['money_original_currency']
            : 'the converted figure ('.($totals['spend'] ?? 0).') — the withheld branch does NOT fire'));

        /*
         * ANALYTICS-PROVENANCE-001 — what the badge beside those figures will SAY.
         *
         * «Demo» used to be a literal in the JSX of four pages, so every project was labelled demo
         * beside its own money. It now derives from the rows, which means the claim is checkable —
         * and a claim that cannot be checked on production is not evidence, it is a hope. This is
         * how «the badge is right on the live project» becomes something a person can read rather
         * than something a test asserts about a fixture.
         */
        $provenance = $agg->provenance(Carbon::now()->subDays(29)->startOfDay(), Carbon::now()->endOfDay());

        $this->line('');
        $this->line('  WHAT THE PROVENANCE BADGE READS (MetricsAggregator::provenance, last 30 days)');
        $this->line('  live rows  : '.$provenance['live_rows']);
        $this->line('  demo rows  : '.$provenance['demo_rows']);
        $this->line('  → the badge will show: '.match ($provenance['source']) {
            'live' => 'NOTHING — real data carries no warning, which is the whole point',
            'demo' => '«بيانات تجريبية · Demo»',
            'mixed' => '«بيانات مختلطة · Mixed» — live and demo rows in one project',
            default => 'NOTHING — there are no rows in this window to characterise',
        });

        if ($provenance['source'] === 'mixed') {
            $this->warn('  Demo rows are sitting inside a project that also holds real ones. The badge '
                .'says so, but the TOTALS above add them together — run `demo:remove` for this tenant.');
        }

        /*
         * Both contexts released together, after the LAST scoped read.
         *
         * `totals()` and `provenance()` both need them; releasing between the two would make the
         * badge report on a scope the figures above it were not read under, which is the same class
         * of quiet disagreement this block exists to catch.
         */
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        $this->line('');
        $this->line('  CREATIVE METRICS — whether the numbers exist, not just the creatives');
        $this->line("  creative_daily_metrics rows : {$metricRows}");
        $this->line('  creatives with any figure   : '.$withMetrics.' of '.$creativeIds->count());
        $this->line('  latest metric_date          : '.($latest ?? '—'));

        /*
         * SNAP-CREATIVE-METRICS-LIVE-001 — the column that decides what the library OPENS on.
         *
         * The counts above were both true and useless for the actual defect: 814 rows existed and
         * the library still read as empty, because its default order is `last_active_at DESC` and
         * nothing in the pipeline had ever written that column. Counting metric rows could not
         * distinguish «the numbers are missing» from «the numbers are on page 47».
         *
         * So this is the number to read after a deploy: if delivering creatives have no last active
         * day, the sort has nothing to work with and the first page is arbitrary again.
         */
        $active = $creativeIds->isEmpty() ? 0 : ExternalCreative::withoutGlobalScopes()
            ->whereIn('id', $creativeIds->all())
            ->whereNotNull('last_active_at')
            ->count();

        $this->line('  creatives with a last active day : '.$active.' of '.$creativeIds->count()
            .'  ← the library sorts on this; 0 means the first page is arbitrary');

        /*
         * METRICS-BACKBONE-001 — whether the two new rungs actually have numbers in them.
         *
         * `entity_daily_metrics` was created, its ingest written, its aggregator built and its API
         * exposed — and for a while nothing called any of it, so the table sat empty in production
         * while every test passed. There is no way to tell «wired but not yet swept» from «wired and
         * broken» without asking the table, and this is the line that asks.
         *
         * Counted per grain because they fail independently: `breakdown=adsquad` is a different call
         * to a different endpoint from `breakdown=ad`, and one can work while the other is refused.
         */
        $entityRows = DB::table('entity_daily_metrics')
            ->where('project_id', $projectId)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("COUNT(*) FILTER (WHERE entity_type = 'ad_set') AS ad_sets")
            ->selectRaw("COUNT(*) FILTER (WHERE entity_type = 'ad') AS ads")
            ->selectRaw('COUNT(DISTINCT entity_id) FILTER (WHERE entity_type = \'ad_set\') AS distinct_ad_sets')
            ->selectRaw('COUNT(DISTINCT entity_id) FILTER (WHERE entity_type = \'ad\') AS distinct_ads')
            ->selectRaw('MAX(metric_date) AS latest')
            ->first();

        /*
         * SNAP-MEDIA-OBSERVABILITY-001 — why the Content library shows blank cards, in counts.
         *
         * Every number here is derived from stored rows rather than from a log, and none of them is
         * a URL: a signed media link written to a log is the leak this product refuses. What the
         * counts separate is «the platform was never asked» (media_id absent), «asked and nothing
         * came back» (media_id present, both url columns empty) and «we have the file» — three
         * states that all render as an empty card and call for completely different fixes.
         */
        $media = DB::table('external_creatives')
            ->where('project_id', $projectId)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COUNT(*) FILTER (WHERE asset_url IS NOT NULL) AS with_image')
            ->selectRaw('COUNT(*) FILTER (WHERE video_url IS NOT NULL) AS with_video')
            ->selectRaw('COUNT(*) FILTER (WHERE thumbnail_url IS NOT NULL) AS with_thumb')
            ->selectRaw('COUNT(*) FILTER (WHERE asset_url IS NULL AND video_url IS NULL AND thumbnail_url IS NULL) AS with_nothing')
            ->first();

        /*
         * CONTENT-KPI-TRACE-001 — three real creatives, followed from the row to what the card reads.
         *
         * «The cards show no performance indicators» has at least four different causes, and a
         * count of rows separates none of them: the creatives on screen may simply be the ones that
         * never delivered; the metrics may exist outside the window the page asks for; the read may
         * be dropping them; or the figures may be there and the card not rendering them.
         *
         * So this walks the SAME path the library walks — the top creatives by last active day,
         * which is exactly what the default sort puts on page one — and prints what
         * `CreativeMetrics` returns for them over the library's own default window. If these show
         * figures, the break is in the browser; if they do not, it is here.
         */
        $traced = ExternalCreative::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->whereNotNull('last_active_at')
            ->orderByDesc('last_active_at')
            ->limit(3)
            ->get(['id', 'name', 'external_creative_id', 'campaign_id', 'last_active_at']);

        $this->line('');
        $this->line('  CREATIVE KPI TRACE — the first three cards the library would show');

        if ($traced->isEmpty()) {
            $this->warn('  No creative has a last active day, so page one is arbitrary and none of '
                .'them will carry figures.');
        } else {
            // The library's own default: the last thirty days, inclusive of today.
            $from = Carbon::now()->subDays(29)->startOfDay();
            $to = Carbon::now()->endOfDay();

            $figures = app(CreativeMetrics::class)->forCreatives(
                $traced->modelKeys(),
                $from,
                $to,
            );

            $this->line('  window: '.$from->toDateString().' → '.$to->toDateString());

            foreach ($traced as $creative) {
                $m = $figures[(string) $creative->getKey()] ?? null;

                $campaign = $creative->campaign_id === null
                    ? null
                    : UnifiedCampaign::withoutGlobalScopes()->find($creative->campaign_id, ['objective']);

                $objective = $campaign?->objective;

                /*
                 * The figures are passed, because the CARD passes them.
                 *
                 * `headline($objective)` alone returns what the objective's FAMILY wants, and since
                 * CONTENT-KPI-AVAILABILITY-001 that is not what a creative is headlined on — the
                 * row's own availability decides which of those survive. Asking without figures made
                 * this line report `spend, orders, cpa, revenue` for a creative whose card shows
                 * `spend, orders, conversion_rate, impressions`, which is exactly the kind of
                 * diagnosis that sends somebody to fix a defect that is not there.
                 *
                 * The diagnosis and the product must never run different algorithms.
                 */
                $headline = app(CreativeMetrics::class)->headline($objective, $m);

                $this->line('  · '.Str::limit((string) $creative->name, 34).'  ['.$creative->external_creative_id.']');

                /*
                 * The card does not render every figure it holds — it renders the FOUR its objective
                 * chose. So the objective is part of the evidence: a creative whose campaign carries
                 * no objective is headlined on the conversion set, and a Snapchat awareness buy has
                 * no conversions to show there. That reads as «no indicators» on screen while the
                 * row underneath is full.
                 */
                $this->line('      objective  : '.($objective ?? 'none')
                    .'   card shows: '.($headline === []
                        ? '(nothing — «لا توجد مؤشرات أداء قابلة للعرض», not «لم يعمل»)'
                        : implode(', ', array_slice($headline, 0, 4))));

                if ($m === null) {
                    $this->warn('      no figures returned for the library window — the row exists but the read gave nothing');

                    continue;
                }

                /*
                 * Money first, because it is the one that has been wrong in three different ways.
                 * A withheld figure prints its ORIGINAL and its currency, which is what the card
                 * must show — «0 SAR» here would mean the contract is being lost on the way out.
                 */
                $this->line('      spend      : '.($m['spend'] ?? 'null')
                    .'   original '.($m['spend_original'] ?? 'null')
                    .' '.($m['money_original_currency'] ?? '')
                    .'  withheld_rows '.($m['spend_withheld_rows'] ?? 0));
                $this->line('      impressions: '.($m['impressions'] ?? 'null')
                    .'   clicks '.($m['clicks'] ?? 'null')
                    .'   ctr '.($m['ctr'] ?? 'null'));
                $this->line('      efficiency : cpc '.($m['cpc'] ?? 'null')
                    .'   cpm '.($m['cpm'] ?? 'null')
                    .'   frequency '.($m['frequency'] ?? 'null'));
                $this->line('      reach      : '.($m['reach'] ?? 'null')
                    .'   video_views '.($m['video_views'] ?? 'null')
                    .'   p100 '.($m['video_p100'] ?? 'null'));
                $this->line('      results    : conversions '.($m['conversions'] ?? 'null')
                    .'   revenue '.($m['revenue'] ?? 'null')
                    .'   original '.($m['revenue_original'] ?? 'null')
                    .'   roas '.($m['roas'] ?? 'null'));

                /*
                 * `reported` is the map the card uses to tell a measured zero from a metric the
                 * platform never sent — the distinction the whole contract rests on. Printing WHICH
                 * keys came back separates «Snapchat reports no conversions for this buy» from «the
                 * read lost them», and those two have nothing in common except how they look.
                 */
                $sent = array_keys(array_filter((array) ($m['reported'] ?? [])));
                sort($sent);
                $this->line('      reported by the platform: '
                    .($sent === [] ? 'nothing' : implode(', ', $sent)));
                $this->line('      active_days: '.($m['active_days'] ?? 0));
            }
        }

        /*
         * CONTENT-KPI-COVERAGE-001 — why only a fraction of the creatives carry any figure.
         *
         * «86 of 1456» is a single number covering at least five different situations, and they call
         * for five different fixes — or, in two cases, for none at all. A creative that never ran in
         * the window it is being asked about is not a defect; a creative whose AD demonstrably ran in
         * that same window while the creative row stayed empty is either a platform that does not
         * break that result down per creative, or a row this product lost.
         *
         * Every bucket below is counted from rows this database already holds. Nothing is asked of
         * the provider, nothing is inferred, and — this is the one that matters — no campaign or ad
         * figure is projected downwards onto a creative. A creative appears in the «its ad ran»
         * bucket because its AD has rows, and that is reported as a fact about the ad.
         */
        $inWindow = DB::table('creative_daily_metrics')
            ->where('project_id', $projectId)
            ->whereBetween('metric_date', [
                Carbon::now()->subDays(29)->toDateString(),
                Carbon::now()->toDateString(),
            ])
            ->distinct()
            ->pluck('creative_id');

        $everRows = DB::table('creative_daily_metrics')
            ->where('project_id', $projectId)
            ->distinct()
            ->pluck('creative_id');

        $creativesInProject = DB::table('external_creatives')
            ->where('project_id', $projectId)
            ->pluck('id');

        $totalCreatives = $creativesInProject->count();
        $haveWindow = $inWindow->intersect($creativesInProject)->count();
        // Rows exist, but every one of them falls outside the thirty days the library asks for.
        $onlyOutside = $everRows->intersect($creativesInProject)->diff($inWindow)->count();

        $silent = $creativesInProject->diff($everRows);

        /*
         * Of the creatives with no row of their own: which are carried by an ad that DID deliver?
         *
         * `external_ads.creative_id` is the canonical link. An ad with `entity_daily_metrics` rows in
         * the same window is an ad the platform reported on — so its creative being empty is a
         * genuine question, and the ones whose ads were equally silent are simply creatives that did
         * not run.
         */
        $deliveringAdCreatives = DB::table('external_ads')
            ->where('project_id', $projectId)
            ->whereNotNull('creative_id')
            ->whereIn('id', function ($sub) use ($projectId): void {
                $sub->select('entity_id')
                    ->from('entity_daily_metrics')
                    ->where('project_id', $projectId)
                    ->where('entity_type', 'ad')
                    ->whereBetween('metric_date', [
                        Carbon::now()->subDays(29)->toDateString(),
                        Carbon::now()->toDateString(),
                    ]);
            })
            ->distinct()
            ->pluck('creative_id');

        $linkedCreatives = DB::table('external_ads')
            ->where('project_id', $projectId)
            ->whereNotNull('creative_id')
            ->distinct()
            ->pluck('creative_id');

        $silentButAdRan = $silent->intersect($deliveringAdCreatives)->count();
        $silentAdAlsoSilent = $silent->intersect($linkedCreatives)->diff($deliveringAdCreatives)->count();
        $silentUnlinked = $silent->diff($linkedCreatives)->count();

        $this->line('');
        $this->line('  CREATIVE KPI COVERAGE — the same creatives, split by WHY they carry no figure');
        $this->line('  figures inside the library window   : '.$haveWindow.' of '.$totalCreatives);
        $this->line('  rows exist but ALL outside it       : '.$onlyOutside
            .($onlyOutside > 0 ? '  ← the page asks for the wrong days' : ''));
        $this->line('  no rows; its ad DID run in window   : '.$silentButAdRan
            .($silentButAdRan > 0 ? '  ← the platform reported the ad and not the creative' : ''));
        $this->line('  no rows; its ad did not run either  : '.$silentAdAlsoSilent
            .'  ← did not deliver; an empty card here is correct');
        $this->line('  no rows; referenced by no ad at all : '.$silentUnlinked
            .($silentUnlinked > 0 ? '  ← structurally invisible to any downward walk' : ''));

        $accounted = $haveWindow + $onlyOutside + $silentButAdRan + $silentAdAlsoSilent + $silentUnlinked;

        // The buckets are built from set operations that could silently overlap or miss. Saying so
        // out loud is cheaper than trusting five counts that happen to look plausible.
        if ($accounted !== $totalCreatives) {
            $this->warn('  the buckets total '.$accounted.', not '.$totalCreatives
                .' — they are not a partition and should not be read as one');
        }

        /*
         * CONTENT-KPI-COVERAGE-002 — what the creative sweep RECEIVED, beside what it wrote.
         *
         * The census above says 34 creatives have no figures while their AD demonstrably ran in the
         * same window. Stored rows cannot say why: «the platform returned no creative-grain row for
         * it», «it returned one under an id this project could not resolve» and «the id resolved to
         * two rows and failed closed» all leave the identical empty table, and each is fixed
         * somewhere different.
         *
         * These come from the sweep's own record. `unmapped_sample` is a handful of the PROVIDER's
         * creative ids — the thing somebody pastes into Snapchat's own interface to check — and it is
         * an id, not a credential.
         */
        $creativeMeta = $this->latestMetaCarrying($projectId, 'creative_ids_received');

        $cm = $creativeMeta;

        $this->line('');
        $this->line('  CREATIVE SWEEP — what the platform sent, and what could be placed');

        if (! array_key_exists('creative_ids_received', $cm)) {
            $this->line('  nothing recorded — no sweep has run since this was wired');
        } else {
            $received = (int) $cm['creative_ids_received'];
            $mapped = (int) ($cm['creative_ids_mapped'] ?? 0);
            $unmapped = (int) ($cm['creative_ids_unmapped'] ?? 0);

            $this->line('  provider rows received     : '.(int) ($cm['creative_rows_received'] ?? 0));
            $this->line('  distinct creative ids sent : '.$received);
            $this->line('  ids we could place         : '.$mapped);
            $this->line('  ids we could NOT place     : '.$unmapped
                .($unmapped > 0 ? '  ← the platform named these and this project has no such creative' : ''));
            $this->line('  ids that resolved twice    : '.(int) ($cm['creative_ids_ambiguous'] ?? 0)
                .'  ← failed closed on purpose; picking one would be a coin toss');
            $this->line('  rows written / skipped     : '.(int) ($cm['creative_rows'] ?? 0)
                .' / '.(int) ($cm['creative_rows_skipped'] ?? 0));

            $sample = (array) ($cm['creative_unmapped_sample'] ?? []);

            if ($sample !== []) {
                $this->line('  a few unplaceable ids      : '.implode(', ', array_map(strval(...), $sample)));
            }

            /*
             * The subtraction that actually answers the question. If the platform never NAMED the
             * creatives in bucket 3, no amount of mapping work will produce their figures — the
             * request or the platform's own coverage is where to look next.
             */
            $this->line('  → of the creatives whose ad ran but which carry no figure, the ones the '
                .'platform never named are '.($unmapped === 0 ? 'ALL of them' : 'all but at most '.$unmapped));
        }

        /*
         * OBJECTIVE-NORMALIZATION-003 — why 71 of 87 campaigns could not be classified.
         *
         * The repair migration reported «87 examined, 16 reclassified, 71 left unclassified», and
         * that second number is not objective coverage complete. `other` is a valid canonical value
         * and the platform's own word is preserved beside it, so nothing was lost — but 71 of 87 is
         * most of the account, and a resolver that declines that often is either meeting words
         * nobody has mapped or meeting no words at all. Those are different problems.
         *
         * Read-only, and counted rather than sampled into a guess. Nothing here changes a mapping.
         */
        $unclassified = UnifiedCampaign::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->where('objective', CampaignObjective::Other->value)
            ->get(['id', 'objective_platform_value', 'objective_source']);

        $this->line('');
        $this->line('  UNCLASSIFIED OBJECTIVES — why the resolver declined');

        if ($unclassified->isEmpty()) {
            $this->line('  none — every campaign in this project carries a classified objective');
        } else {
            $map = app(PlatformObjectiveMap::class);

            $noRaw = 0;          // the platform stated nothing at all
            $unmapped = 0;       // it stated a word this product does not know
            $conflicting = 0;    // its linked campaigns disagree
            $noLinks = 0;        // nothing linked, so there was nothing to read
            $resolvable = 0;     // it WOULD resolve now — a repair that has not been re-run

            foreach ($unclassified as $campaign) {
                $externals = ExternalCampaign::withoutGlobalScopes()
                    ->where('unified_campaign_id', $campaign->id)
                    ->get(['provider', 'objective']);

                if ($externals->isEmpty()) {
                    $noLinks++;

                    continue;
                }

                $resolved = [];
                $stated = 0;

                foreach ($externals as $external) {
                    if (trim((string) $external->objective) !== '') {
                        $stated++;
                    }

                    $case = $map->resolve((string) $external->provider, $external->objective);

                    if ($case !== null) {
                        $resolved[$case->value] = true;
                    }
                }

                if ($stated === 0) {
                    $noRaw++;
                } elseif ($resolved === []) {
                    $unmapped++;
                } elseif (count($resolved) > 1) {
                    $conflicting++;
                } else {
                    // One agreed answer, and the campaign still sits at `other` — the repair simply
                    // has not been run since the mapping learned this word.
                    $resolvable++;
                }
            }

            $this->line('  campaigns at «other»            : '.$unclassified->count());
            $this->line('  the platform stated nothing     : '.$noRaw);
            $this->line('  it stated a word we do not know : '.$unmapped
                .($unmapped > 0 ? '  ← a mapping question, not a bug' : ''));
            $this->line('  its linked campaigns disagree   : '.$conflicting
                .'  ← left for a person on purpose');
            $this->line('  nothing linked to read from     : '.$noLinks);
            $this->line('  would resolve if repaired again : '.$resolvable
                .($resolvable > 0 ? '  ← re-run the repair' : ''));

            /*
             * The words themselves, deduplicated and capped. A count of «unmapped» tells nobody
             * WHICH word to add, and the word is the entire content of that question.
             */
            $words = $unclassified
                ->pluck('objective_platform_value')
                ->filter(static fn (mixed $v): bool => is_string($v) && trim($v) !== '')
                ->unique()
                ->take(12)
                ->values();

            if ($words->isNotEmpty()) {
                $this->line('  the words they carry            : '.$words->implode(', '));
            }
        }

        $this->line('');
        $this->line('  CREATIVE MEDIA — whether the asset ever reached the row');
        $this->line('  creatives            : '.(int) ($media->total ?? 0));
        $this->line('  with an image file   : '.(int) ($media->with_image ?? 0));
        $this->line('  with a video file    : '.(int) ($media->with_video ?? 0));
        $this->line('  with a thumbnail     : '.(int) ($media->with_thumb ?? 0));
        $this->line('  with NO asset at all : '.(int) ($media->with_nothing ?? 0)
            .'  ← these are the blank cards');

        /*
         * What the last structure sweep recorded about the media call itself. «asked» far above
         * «resolved» means media is being fetched and then rejected — the signed-URL classification
         * was one such cause; both zero means it was never asked for.
         */
        $lastRun = IntegrationSyncRun::withoutGlobalScopes()
            ->where('type', 'structure')
            ->whereNotNull('meta')
            ->orderByDesc('started_at')
            ->value('meta');

        $runMeta = is_array($lastRun) ? $lastRun : (array) json_decode((string) $lastRun, true);
        $mediaMeta = (array) ($runMeta['media'] ?? []);

        if ($mediaMeta !== []) {
            $this->line('  last sweep asked for : '.(int) ($mediaMeta['asked'] ?? 0)
                .' media id(s), resolved '.(int) ($mediaMeta['resolved'] ?? 0));

            if (($mediaMeta['error'] ?? null) !== null) {
                $this->warn('  the platform refused : '.$mediaMeta['error']);
            }
        } else {
            $this->line('  last sweep asked for : nothing recorded — no structure sweep has run since '
                .'media reporting was added');
        }

        $this->line('');
        $this->line('  AD SET / AD METRICS — the rungs Analytics could not show before');
        $this->line('  entity_daily_metrics rows : '.(int) ($entityRows->total ?? 0));
        $this->line('  ad_set rows / entities    : '.(int) ($entityRows->ad_sets ?? 0).' / '.(int) ($entityRows->distinct_ad_sets ?? 0));
        $this->line('  ad rows / entities        : '.(int) ($entityRows->ads ?? 0).' / '.(int) ($entityRows->distinct_ads ?? 0));
        $this->line('  latest metric_date        : '.($entityRows->latest ?? '—'));

        /*
         * What the last sweep RECORDED about these grains — the answer the row count cannot give.
         *
         * `entity_failure` is the first refusal the connector met while sweeping; `entity_ad_sets`
         * and `entity_ads` are what it managed to write. Together they separate «never swept» from
         * «swept and refused», which a count of zero cannot.
         */
        $lastMeta = $this->latestMetaCarrying($projectId, 'entity_ad_sets');

        $meta = $lastMeta;

        if (array_key_exists('entity_ad_sets', $meta)) {
            $this->line('  last sweep wrote          : '.(int) $meta['entity_ad_sets'].' ad-set row(s), '
                .(int) ($meta['entity_ads'] ?? 0).' ad row(s)');

            if (($meta['entity_failure'] ?? null) !== null) {
                $this->warn('  the platform refused     : '.$meta['entity_failure']);
            }
        } else {
            $this->line('  last sweep wrote          : nothing recorded — no sweep has run since the '
                .'ingest was wired');
        }

        if ((int) ($entityRows->total ?? 0) === 0) {
            $this->warn('  Empty. Read the two lines above: «nothing recorded» means no sweep has run '
                .'since the ingest shipped; a refusal names what the platform said.');
        }

        if ($withMetrics > 0 && $active === 0) {
            $this->warn('  Creatives HAVE figures but none has a last active day. The delivering ones are '
                .'scattered through the pager and the library will look empty — run the backfill.');
        }

        if ($metricRows === 0 && $creativeIds->isNotEmpty()) {
            $this->warn('  No creative-level figures at all. Every creative will read «لا توجد بيانات» — '
                .'correctly, because nothing has been fetched for them.');
        }

        $this->line('');
        $this->line('  Orphaned ad squads and ads cannot appear above: `external_campaign_id` is NOT NULL on');
        $this->line('  both tables, so a row naming an undiscovered parent is REJECTED at import rather than');
        $this->line('  stored detached. It is counted as `skipped` and makes the run `partial_mapping` —');
        $this->line('  the structure runs printed above carry that count, and it is the number to read.');
    }

    /**
     * INTEG-RUNTIME §10 — what the assigned PROJECT now holds, in the tables every surface reads.
     *
     * ## Why this is the honest way to prove the chain from a console
     *
     * The dashboard, analytics, campaigns, reports and the client link do not each keep their own
     * copy of anything: they all read `daily_metrics` and `unified_campaigns`, scoped by project. So
     * «did the data reach the surfaces» is answerable without a session — the question is whether
     * those two tables hold it, for the project the binding names and for no other.
     *
     * What this deliberately does NOT claim is that a screen rendered. That is a different assertion,
     * held by the end-to-end suite, and a console cannot make it.
     */
    /**
     * The most recent run that RECORDED a given key — not simply the most recent run.
     *
     * Both blocks below used to read the newest `meta` and report «nothing recorded» when the key
     * was absent from it. That reads as «no sweep has ever run», and it is a different statement.
     *
     * It was wrong on production within an hour of shipping: a structure sweep wrote a run whose
     * meta carries its own keys and not the metrics sweep's, so a diagnosis that had been printing
     * «last sweep wrote 172 ad-set row(s), 1165 ad row(s)» — and, crucially, the ad-stats refusal
     * beside it — went silent. Nothing had changed about the sweep. The newest row simply answered
     * a different question, and the refusal being missing looked exactly like the refusal being
     * fixed.
     *
     * A diagnosis that can turn a symptom off by accident is worse than one that says nothing, so
     * this asks for the newest run that actually holds the key.
     *
     * @return array<string, mixed>
     */
    private function latestMetaCarrying(string $projectId, string $key): array
    {
        $meta = MetricSyncRun::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->whereNotNull('meta->'.$key)
            ->orderByDesc('started_at')
            ->value('meta');

        if (is_array($meta)) {
            return $meta;
        }

        return (array) json_decode((string) $meta, true);
    }

    private function reportDownstream(ExternalAccount $account, ?string $projectId, ?string $projectName): void
    {
        $this->line('');
        $this->line('  DOWNSTREAM — what the project now holds');

        if ($projectId === null) {
            $this->line('  This account is not linked to a project, so nothing downstream is expected.');

            return;
        }

        $metrics = DailyMetric::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->where('external_account_id', $account->getKey());

        $rows = (clone $metrics)->count();
        $days = (clone $metrics)->distinct()->count('metric_date');
        $spend = (float) (clone $metrics)->where('metric_key', 'spend')->sum('value');
        $purchases = (float) (clone $metrics)->where('metric_key', 'purchases')->sum('value');
        $revenue = (float) (clone $metrics)->where('metric_key', 'revenue')->sum('value');

        /*
         * FX-001 — money is WITHHELD, not zeroed, when no rate can be vouched for.
         *
         * `sum(value)` over withheld rows is 0, and printing that as «spend 0.00» says the account
         * spent nothing — which is the opposite of the truth and exactly the mis-statement the
         * withholding exists to avoid. The original amount survives on every row, so both are shown
         * and the withheld count says which number to read.
         */
        $withheld = (clone $metrics)->whereIn('metric_key', ['spend', 'revenue'])->whereNull('value')->count();
        $spendOriginal = (float) (clone $metrics)->where('metric_key', 'spend')->sum('original_amount');
        $revenueOriginal = (float) (clone $metrics)->where('metric_key', 'revenue')->sum('original_amount');
        $sourceCurrency = (string) ((clone $metrics)->whereNotNull('original_currency')->value('original_currency') ?? '');
        $latest = (clone $metrics)->max('metric_date');

        $external = ExternalCampaign::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->where('project_id', $projectId);

        $this->line(sprintf('  project           : %s', $projectName ?? $projectId));
        $this->line(sprintf('  daily_metric rows : %d across %d day(s), latest %s', $rows, $days, $latest ?? '—'));
        $this->line(sprintf('  purchases         : %s', number_format($purchases, 0)));
        $this->line(sprintf(
            '  spend / revenue   : %s / %s in the project currency%s',
            number_format($spend, 2),
            number_format($revenue, 2),
            $withheld > 0 ? '  ← WITHHELD, see below' : '',
        ));
        $this->line(sprintf(
            '  as the platform reported it: %s / %s %s',
            number_format($spendOriginal, 2),
            number_format($revenueOriginal, 2),
            $sourceCurrency !== '' ? $sourceCurrency : '(currency not recorded)',
        ));

        if ($withheld > 0) {
            $this->line(sprintf(
                '  %d money row(s) are WITHHELD: no %s→project exchange rate exists, so the figure is '
                    .'null rather than wrong. Each converts itself the day a rate is available (FX-FEED-001).',
                $withheld,
                $sourceCurrency !== '' ? $sourceCurrency : 'source',
            ));
        }
        $this->line(sprintf('  campaigns visible : %d external, %d linked to a unified campaign',
            (clone $external)->count(),
            (clone $external)->whereNotNull('unified_campaign_id')->count(),
        ));

        /*
         * The isolation half, and it is the half that matters. «The data arrived» is trivially
         * satisfied by filing everything into the first project to hand — which is the exact defect
         * this programme spent three PRs removing — so the count that must be ZERO is reported too.
         */
        $elsewhere = DailyMetric::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->where('project_id', '!=', $projectId)
            ->count();

        $this->line(sprintf('  rows in ANY OTHER project: %d   (must be 0)', $elsewhere));

        $this->reportUnadopted($account);
    }

    /**
     * The campaigns that did NOT become visible, each with the evidence for why.
     *
     * «87 of 89» is not an answer, it is two unanswered questions. A campaign that never became
     * visible is either a correct product state — somebody detached it, or the platform reports it in
     * a way this product does not adopt — or a defect, and the two look identical from a count.
     *
     * So each one is printed with everything that decides it: what the platform calls it, its status,
     * whether it carries a link, whether it carries a recorded unlink, and whether the audit trail
     * holds a detachment for it. Nothing is inferred here; every column is read.
     */
    private function reportUnadopted(ExternalAccount $account): void
    {
        $orphans = ExternalCampaign::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->whereNull('unified_campaign_id')
            ->orderBy('name')
            ->get(['id', 'external_id', 'name', 'status', 'project_id', 'unlinked_at', 'linked_at', 'created_at', 'updated_at']);

        $this->line('');
        $this->line(sprintf('  Campaigns with NO visible campaign: %d', $orphans->count()));

        if ($orphans->isEmpty()) {
            return;
        }

        foreach ($orphans as $orphan) {
            $audited = DB::table('audit_logs')
                ->where('action', StampHistoricalUnlinks::AUDIT_ACTION)
                ->where('entity_id', (string) $orphan->id)
                ->count();

            /*
             * A campaign whose name is already taken by another visible campaign in the same project
             * is the one shape that can fail adoption without anybody having decided anything:
             * `unified_campaigns` is unique on `(project_id, name)`.
             */
            $nameTaken = UnifiedCampaign::withoutGlobalScopes()
                ->where('project_id', $orphan->project_id)
                ->where('name', $orphan->name)
                ->count();

            $this->line(sprintf(
                '    %s  «%s»  status=%s  project=%s',
                $orphan->external_id,
                $orphan->name,
                $orphan->status,
                $orphan->project_id === null ? 'NONE' : 'set',
            ));
            $this->line(sprintf(
                '      unlinked_at=%s  linked_at=%s  unlink audit entries=%d  same-name visible campaigns=%d',
                $orphan->unlinked_at ?? '—',
                $orphan->linked_at ?? '—',
                $audited,
                $nameTaken,
            ));
            $this->line(sprintf('      first seen %s, last touched %s', $orphan->created_at, $orphan->updated_at));
        }
    }

    /**
     * The STRUCTURE runs — campaigns, ad sets, ads, creatives.
     *
     * A separate pipeline on a separate clock (`integrations:sync-structure`, every six hours) and a
     * separate table, and its outcome was invisible here. That matters: metrics can succeed while
     * structure fails, and the visible result is a project full of figures with no campaign to hang
     * them on — which is exactly the state the live account was found in.
     */
    private function reportStructureRuns(ExternalAccount $account): void
    {
        $runs = IntegrationSyncRun::withoutGlobalScopes()
            ->where('provider_connection_id', $account->provider_connection_id)
            ->orderByDesc('started_at')
            ->limit(4)
            ->get();

        $this->line('');
        $this->line(sprintf('  Structure runs (last %d):', $runs->count()));

        if ($runs->isEmpty()) {
            $this->line('  none recorded.');

            return;
        }

        foreach ($runs as $run) {
            $this->line(sprintf(
                '  %s  %-16s records=%d  %s',
                $run->started_at?->toDateTimeString() ?? '—',
                (string) $run->status,
                (int) $run->records,
                $run->error === null ? '' : mb_substr((string) $run->error, 0, 240),
            ));
        }
    }

    /**
     * The provider's OWN last words about this account, printed.
     *
     * `provider_raw_rows = 0` says the body held no data points. It does not say WHY, and there are
     * two very different whys: the account genuinely had no delivery in the window, or we asked a
     * question this account cannot answer and the platform replied politely with nothing. Only the
     * body distinguishes them, and it is already stored — so the answer is a read, not a theory.
     *
     * Truncated, because a stats body can be large and this goes into a log a human reads.
     */
    private function reportPayload(ExternalAccount $account, ?MetricSyncRun $run): void
    {
        if ($run === null) {
            return;
        }

        $payloads = IntegrationRawPayload::withoutGlobalScopes()
            ->where('sync_run_id', $run->getKey())
            ->where('resource', 'insights')
            ->get(['payload', 'window_start', 'window_end', 'fetched_at']);

        $this->line('');
        $this->line(sprintf('  Retained bodies for the last run: %d', $payloads->count()));

        foreach ($payloads->take(3) as $index => $payload) {
            $this->line(sprintf(
                '  [%d] window %s → %s, fetched %s',
                $index,
                $payload->window_start?->toDateString() ?? '?',
                $payload->window_end?->toDateString() ?? '?',
                $payload->fetched_at?->toDateTimeString() ?? '?',
            ));
            $this->line('  '.mb_substr(json_encode($payload->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', 0, 1200));
        }

        // What the mapper WOULD have had to match against, if a row had arrived.
        $campaigns = ExternalCampaign::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get(['external_id', 'name', 'status']);

        $this->line('');
        $this->line('  Campaigns discovered for this account (5 most recently updated):');
        foreach ($campaigns as $campaign) {
            $this->line(sprintf('    %s  %s  [%s]', $campaign->external_id, $campaign->name, $campaign->status));
        }
    }

    /**
     * One run as a row, with the counts either as measured or as recovered from the kept payload.
     *
     * @return list<string>
     */
    private function runRow(MetricSyncRun $run, ExternalAccount $account): array
    {
        $measured = $run->parsed_rows !== null || $run->provider_raw_rows !== null;

        $counts = $measured
            ? [
                'raw' => $run->provider_raw_rows,
                'parsed' => $run->parsed_rows,
                'mapped' => $run->mapped_campaign_rows,
            ]
            : $this->reconstruct($run, $account);

        return [
            $run->started_at?->toDateTimeString() ?? '—',
            ($run->window_start?->toDateString() ?? '?').' → '.($run->window_end?->toDateString() ?? '?'),
            (string) $run->status,
            $this->cell($counts['raw'] ?? null),
            $this->cell($counts['parsed'] ?? null),
            $this->cell($counts['mapped'] ?? null),
            (string) (int) $run->metrics_upserted,
            $measured ? 'measured' : ($counts === [] ? 'no payload kept' : 'recovered from payload'),
        ];
    }

    /**
     * Recover what can be recovered for a run recorded before the counters existed.
     *
     * `parsed` is deliberately absent here and must stay absent: parsing is what the connector did at
     * the time, and re-running it now against a body would measure TODAY's connector, not the one
     * that produced this run. Raw records and the campaign ids they named are facts about the body
     * itself, so those two are recoverable and honest.
     *
     * @return array<string,int|null>
     */
    private function reconstruct(MetricSyncRun $run, ExternalAccount $account): array
    {
        $payloads = IntegrationRawPayload::withoutGlobalScopes()
            ->where('sync_run_id', $run->getKey())
            ->where('resource', 'insights')
            ->get(['payload']);

        if ($payloads->isEmpty()) {
            return [];
        }

        $rows = 0;
        $ids = [];
        $readable = false;

        foreach ($payloads as $payload) {
            $read = InsightPayloadRows::of($account->provider, (array) $payload->payload);

            if ($read === null) {
                continue;
            }

            $readable = true;
            $rows += $read['rows'];
            $ids = [...$ids, ...$read['campaign_ids']];
        }

        if (! $readable) {
            return [];
        }

        $ids = array_values(array_unique($ids));

        $known = $ids === [] ? 0 : ExternalCampaign::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->whereIn('external_id', $ids)
            ->count();

        return [
            'raw' => $rows,
            'parsed' => null,
            // Campaign IDENTITIES the body named that we know about — not rows. Stated as such in the
            // report: it answers «could these rows have been placed at all?», which is the question.
            'mapped' => $known,
        ];
    }

    private function cell(?int $value): string
    {
        return $value === null ? '—' : (string) $value;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
