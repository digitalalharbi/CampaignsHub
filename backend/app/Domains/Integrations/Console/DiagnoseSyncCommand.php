<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Campaigns\Actions\StampHistoricalUnlinks;
use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
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
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
        $agg = app(MetricsAggregator::class)->forProjects([(string) $projectId]);
        app(ProjectContext::class)->setProjectId((string) $projectId);
        $totals = $agg->totals(Carbon::now()->subDays(29)->startOfDay(), Carbon::now()->endOfDay());
        app(ProjectContext::class)->forget();

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
        $lastMeta = MetricSyncRun::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->whereNotNull('meta')
            ->orderByDesc('started_at')
            ->value('meta');

        $meta = is_array($lastMeta) ? $lastMeta : (array) json_decode((string) $lastMeta, true);

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
