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
use App\Domains\Projects\Models\Project;
use Illuminate\Console\Command;
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
            .'   (Google Ads and LinkedIn send none at all, so 0 is not expected there)');
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

        // Google Ads and LinkedIn send no creative with an ad at all, so 0 there is the shape, not a gap.
        if ($adsWithoutCreative > 0 && ! in_array($account->provider, ['google_ads', 'linkedin'], true)) {
            $this->warn("  {$adsWithoutCreative} ad(s) carry no creative on a provider that sends one.");
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
