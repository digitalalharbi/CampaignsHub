<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SNAP-STRUCTURE-RETRY-001 §2 — the counts, and the orphans that make the counts mean something.
 *
 * A sweep reporting «11,686 records» says nothing about shape: every one of those rows could be a
 * campaign, or every ad could be filed under nothing, and the total would read the same. So the
 * report has to state both what was discovered AND whether it was placed — an unplaced row is
 * invisible on every screen that walks the hierarchy downwards, which is most of them.
 *
 * These tests plant one orphan at each level and prove the report names it, because a counter that
 * only ever prints zero is indistinguishable from one that cannot count.
 */
final class HierarchyCountsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'H', 'slug' => 'h-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => 'P', 'status' => 'active',
        ]);

        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'snapchat',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: 'snapchat',
        );

        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'snapchat',
            'account_type' => 'ad_account',
            'external_id' => 'act_snap',
            'name' => 'Snap',
            'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);
    }

    public function test_a_healthy_hierarchy_is_counted_at_every_level_with_no_orphans(): void
    {
        $campaign = $this->campaign('cmp-1');
        $adSet = $this->adSet('sq-1', $campaign);
        $ad = $this->ad('ad-1', $adSet, $campaign);
        $this->creative('cr-1', $ad, $campaign);

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat', '--hierarchy' => true])
            ->expectsOutputToContain('HIERARCHY')
            ->assertSuccessful();

        $this->assertSame(1, ExternalAdSet::withoutGlobalScopes()->whereNotNull('external_campaign_id')->count());
        /*
         * The creative is linked through `external_ads.creative_id` — CREATIVE-COLUMN-RETIRE-001.
         *
         * This counted creatives whose `external_ad_id` was set, which asked «has this creative ever
         * been linked to an ad» of a column holding whichever ad was imported LAST. The relation
         * answers the same question without the false singular, and it is the column the report
         * itself reads.
         */
        $this->assertSame(1, ExternalAd::withoutGlobalScopes()->whereNotNull('creative_id')->count());
    }

    /**
     * The invariant the report leans on: an orphaned ad squad cannot be STORED.
     *
     * `external_ad_sets.external_campaign_id` is NOT NULL with a cascading foreign key. So «orphan ad
     * squads» is not a number to count — it is a state the schema forbids, and rows that would have
     * it are rejected at import, counted as `skipped`, and turn the run `partial_mapping`. Printing a
     * column for it would print 0 for ever whatever happened, which is the shape of reassuring lie
     * this whole ticket is about. This test is what stops that column from being added back.
     */
    public function test_an_ad_squad_with_no_campaign_cannot_be_stored_at_all(): void
    {
        $this->expectException(QueryException::class);

        $this->adSet('sq-orphan', null);
    }

    /**
     * The same for ads: the campaign is NOT NULL, only the ad squad is nullable.
     */
    public function test_an_ad_with_no_campaign_cannot_be_stored_at_all(): void
    {
        $this->expectException(QueryException::class);

        $this->ad('ad-orphan', null, null);
    }

    /**
     * The two placements that CAN go wrong, and the report has to name them rather than
     * fold them into a healthy total.
     */
    public function test_the_two_placements_that_can_go_wrong_are_named(): void
    {
        $campaign = $this->campaign('cmp-1');
        $adSet = $this->adSet('sq-1', $campaign);
        $ad = $this->ad('ad-1', $adSet, $campaign);
        $this->creative('cr-1', $ad, $campaign);

        // An ad hanging off the campaign with no squad — correct on LinkedIn, a defect on Snapchat.
        $this->ad('ad-no-squad', null, $campaign);
        // A creative no ad points at — unreachable through `external_ads.creative_id`, which is the
        // relation the report reads. Its `external_ad_id` is irrelevant to that question.
        $this->creative('cr-no-ad', null, $campaign);

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat', '--hierarchy' => true])
            ->expectsOutputToContain('ads with no ad squad         : 1')
            ->expectsOutputToContain('creatives referenced by no ad: 1')
            ->expectsOutputToContain('1 creative(s) are referenced by no ad at all.')
            ->assertSuccessful();
    }

    /**
     * A level that discovered nothing is called out, because «0» beside a provider that returned
     * rows is the defect this section exists to make visible.
     */
    public function test_a_level_that_discovered_nothing_is_called_out(): void
    {
        $this->campaign('cmp-1');

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat', '--hierarchy' => true])
            ->expectsOutputToContain('ad_squads = 0')
            ->expectsOutputToContain('that is a defect')
            ->assertSuccessful();
    }

    /**
     * The counts belong to THIS account. A tenant-wide count would fold in every other connection
     * and answer a question nobody asked — and would have reported a healthy hierarchy for an
     * account that has none.
     */
    public function test_another_accounts_rows_are_not_counted_into_this_ones_hierarchy(): void
    {
        $mine = $this->campaign('cmp-mine');
        $this->adSet('sq-mine', $mine);

        $other = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $this->account->provider_connection_id,
            'provider' => 'snapchat',
            'account_type' => 'ad_account',
            'external_id' => 'act_other',
            'name' => 'Other',
            'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);

        $theirs = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $other->id,
            'provider' => 'snapchat',
            'external_id' => 'cmp-theirs',
            'name' => 'Theirs',
            'status' => 'active',
        ]);

        $this->adSet('sq-theirs-1', $theirs);
        $this->adSet('sq-theirs-2', $theirs);

        $mineIds = ExternalCampaign::withoutGlobalScopes()
            ->where('external_account_id', $this->account->getKey())->pluck('id');

        $this->assertSame(
            1,
            ExternalAdSet::withoutGlobalScopes()->whereIn('external_campaign_id', $mineIds)->count(),
            'The hierarchy is scoped to the account being diagnosed, not to the tenant.',
        );
    }

    /**
     * SNAP-CREATIVE-METRICS-001 — the report distinguishes «no creatives» from «creatives with no
     * numbers», which is the exact confusion that hid this defect for so long.
     */
    public function test_creatives_without_any_figure_are_called_out(): void
    {
        $campaign = $this->campaign('cmp-1');
        $adSet = $this->adSet('sq-1', $campaign);
        $ad = $this->ad('ad-1', $adSet, $campaign);
        $this->creative('cr-1', $ad, $campaign);

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat', '--hierarchy' => true])
            ->expectsOutputToContain('creative_daily_metrics rows : 0')
            ->expectsOutputToContain('No creative-level figures at all')
            ->assertSuccessful();
    }

    /**
     * CONTENT-KPI-TRACE-001 — the trace prints the figures the first card would carry.
     *
     * The library's first page is ordered by last active day, so these three creatives ARE the
     * cards the owner is looking at. If the trace shows figures for them and the screen shows none,
     * the break is downstream of the API; if the trace shows none, it is here. A diagnosis that
     * could only ever print «no figures» would settle nothing, so this plants a real row and
     * asserts the number reaches the output.
     */
    public function test_the_kpi_trace_prints_real_figures_for_the_first_page_of_cards(): void
    {
        $campaign = $this->campaign('cmp-1');
        $adSet = $this->adSet('sq-1', $campaign);
        $ad = $this->ad('ad-1', $adSet, $campaign);
        $creative = $this->creative('cr-1', $ad, $campaign);

        $this->figures($creative, Carbon::today(), spend: 1234.0, impressions: 5000, clicks: 250);

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat', '--hierarchy' => true])
            ->expectsOutputToContain('CREATIVE KPI TRACE')
            ->expectsOutputToContain('cr-1')
            ->expectsOutputToContain('impressions: 5000')
            ->assertSuccessful();
    }

    /**
     * The other half of the same question: a creative that HAS a last active day but whose figures
     * fall outside the library's window must say so, rather than printing a silent blank. That is
     * the case the counts cannot see — the rows exist, and the page still shows nothing.
     */
    public function test_the_kpi_trace_names_a_creative_whose_figures_fall_outside_the_window(): void
    {
        $campaign = $this->campaign('cmp-1');
        $adSet = $this->adSet('sq-1', $campaign);
        $ad = $this->ad('ad-1', $adSet, $campaign);
        $creative = $this->creative('cr-1', $ad, $campaign);

        // Older than the thirty days the library asks for, but recent enough to set the sort column.
        $this->figures($creative, Carbon::today()->subDays(120), spend: 10.0, impressions: 10, clicks: 1);

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat', '--hierarchy' => true])
            ->expectsOutputToContain('CREATIVE KPI TRACE')
            ->expectsOutputToContain('no figures returned for the library window')
            ->assertSuccessful();
    }

    /**
     * CONTENT-KPI-COVERAGE-001 — one creative planted in each bucket, so none can report a lazy zero.
     *
     * «86 of 1456 creatives carry a figure» is one number covering at least five situations that
     * call for different fixes, or for none. This plants a creative in every one of them at once
     * and asserts the census separates them — a report that could only ever print zeros in four
     * buckets would look identical to a healthy account.
     */
    public function test_the_coverage_census_separates_the_reasons_a_creative_has_no_figures(): void
    {
        $campaign = $this->campaign('cmp-1');
        $adSet = $this->adSet('sq-1', $campaign);

        // 1 — delivered inside the window.
        $inWindow = $this->creative('cr-window', $this->ad('ad-1', $adSet, $campaign), $campaign);
        $this->figures($inWindow, Carbon::today(), spend: 10.0, impressions: 100, clicks: 5);

        // 2 — has rows, but every one of them predates the thirty days the library asks for.
        $outside = $this->creative('cr-outside', $this->ad('ad-2', $adSet, $campaign), $campaign);
        $this->figures($outside, Carbon::today()->subDays(120), spend: 10.0, impressions: 100, clicks: 5);

        // 3 — no creative row, but the AD carrying it demonstrably ran in the same window.
        $adRan = $this->ad('ad-3', $adSet, $campaign);
        $this->creative('cr-ad-ran', $adRan, $campaign);
        $this->adFigures($adRan, Carbon::today());

        // 4 — no creative row and its ad was equally silent: it did not deliver.
        $this->creative('cr-silent', $this->ad('ad-4', $adSet, $campaign), $campaign);

        // 5 — referenced by no ad at all.
        $this->creative('cr-orphan', null, $campaign);

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat', '--hierarchy' => true])
            ->expectsOutputToContain('CREATIVE KPI COVERAGE')
            ->expectsOutputToContain('figures inside the library window   : 1 of 5')
            ->expectsOutputToContain('rows exist but ALL outside it       : 1')
            ->expectsOutputToContain('no rows; its ad DID run in window   : 1')
            ->expectsOutputToContain('no rows; its ad did not run either  : 1')
            ->expectsOutputToContain('no rows; referenced by no ad at all : 1')
            ->assertSuccessful();
    }

    /**
     * DIAGNOSE-LATEST-RECORDED-001 — a newer run that answers a DIFFERENT question must not silence
     * the one that answered this one.
     *
     * The entity and creative blocks read the newest `meta` and said «nothing recorded» when their
     * key was absent from it. On production a structure sweep wrote a run whose meta carries its own
     * keys, and a diagnosis that had been printing the ad-stats REFUSAL went silent — which looks
     * exactly like the refusal having been fixed.
     *
     * A diagnosis that can turn a symptom off by accident is worse than one that says nothing.
     */
    public function test_a_newer_unrelated_run_does_not_hide_what_an_older_one_recorded(): void
    {
        $campaign = $this->campaign('cmp-1');
        $adSet = $this->adSet('sq-1', $campaign);
        $ad = $this->ad('ad-1', $adSet, $campaign);
        $this->creative('cr-1', $ad, $campaign);

        // The sweep that recorded the grains, and the refusal beside them.
        $this->metricRun(Carbon::now()->subHour(), [
            'entity_ad_sets' => 172,
            'entity_ads' => 1165,
            'entity_failure' => 'Snapchat Marketing API could not return ad stats: Request URL can not be correctly processed',
        ]);

        // A LATER run, about something else entirely.
        $this->metricRun(Carbon::now(), ['media_asked' => 10, 'media_resolved' => 9]);

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat', '--hierarchy' => true])
            ->expectsOutputToContain('last sweep wrote          : 172 ad-set row(s), 1165 ad row(s)')
            ->expectsOutputToContain('Request URL can not be correctly processed')
            ->assertSuccessful();
    }

    /** And with nothing ever recorded, it still says so rather than inventing a figure. */
    public function test_it_still_reports_nothing_when_no_run_ever_carried_the_key(): void
    {
        $campaign = $this->campaign('cmp-1');
        $this->creative('cr-1', null, $campaign);

        $this->metricRun(Carbon::now(), ['media_asked' => 1]);

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat', '--hierarchy' => true])
            ->expectsOutputToContain('no sweep has run since the ingest was wired')
            ->assertSuccessful();
    }

    /**
     * DIAGNOSE-TENANT-SCOPE-001 — the dashboard block must read what the DASHBOARD reads.
     *
     * `DailyMetric` carries a tenant global scope. A request has a tenant; `artisan` does not. So
     * this block asked the aggregator the dashboard's question with no tenant in context, matched
     * nothing, and printed «impressions (control) : 0» for a project holding real rows — which
     * reads as «the production dashboard shows zero» when the dashboard is fine.
     *
     * The control metric is the assertion: impressions are never withheld and never converted, so a
     * non-zero here is proof the scope resolved.
     */
    public function test_the_dashboard_block_reads_through_the_tenant_scope(): void
    {
        $campaign = $this->campaign('cmp-1');

        $unified = UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'Unified',
            'objective' => 'awareness',
            'status' => 'active',
            'budget_currency' => 'USD',
            'platforms' => ['snapchat'],
        ]);

        $campaign->forceFill(['unified_campaign_id' => $unified->getKey()])->save();

        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $this->account->getKey(),
            'unified_campaign_id' => $unified->getKey(),
            // NOT NULL — a metric row always names the platform campaign it came from.
            'external_campaign_id' => $campaign->getKey(),
            'provider' => 'snapchat',
            'metric_date' => Carbon::today()->toDateString(),
            // `daily_metrics` is key/value shaped — one row per metric, not one column per metric.
            'metric_key' => 'impressions',
            'value' => 4242,
            'is_demo' => false,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat', '--hierarchy' => true])
            ->expectsOutputToContain('impressions (control)  : 4242')
            ->assertSuccessful();
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────

    /**
     * SANDBOX-PROD-001 / SNAP-AD-STATS-ROUTE-001 — a sandbox row under a live account is COUNTED.
     *
     * Production recorded «could not return ad stats … [path: /v1/campaigns/sbx-cmp-1/stats]» on the
     * live bound Snapchat account. The path is well-formed, so the blank-id defect this route already
     * fixed is not what happened: `sbx-cmp-1` is a SANDBOX campaign, and it reached the live API
     * because `AccountMetricsSyncer::syncEntityGrains()` plucks every `external_id` on the account.
     * Forty-eight sweeps a day, each producing a refusal the provider is right to give.
     *
     * The diagnosis counts them rather than filtering them, because HOW a sandbox row came to sit
     * under a live account is not yet known and a filter would hide the rows while silencing the
     * symptom. Counted from the sandbox connector's own `raw.sandbox` marker, not from an id prefix:
     * a prefix is a guess about a string.
     */
    public function test_a_sandbox_campaign_under_a_live_account_is_counted(): void
    {
        $this->campaign('cmp-live');

        $sandbox = $this->campaign('sbx-cmp-1');
        $sandbox->forceFill(['raw' => ['sandbox' => true]])->save();

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat', '--hierarchy' => true])
            ->expectsOutputToContain('SANDBOX campaigns on this account: 1')
            ->assertSuccessful();
    }

    /**
     * SANDBOX-PROD-001 §2 — stored rows are NOT «campaigns discovered».
     *
     * The header counted every `external_campaigns` row and printed it as provider discovery. On the
     * live Meta account those two numbers were 2 and 0: the provider's structure sweep returned
     * `no_data records=0`, and the only rows stored were sandbox campaigns the binding-sync defect
     * wrote. The diagnosis reported a working discovery for the very account whose emptiness was
     * being investigated.
     *
     * One real campaign and one sandbox row must therefore read «provider campaigns=1», never 2.
     */
    public function test_the_header_separates_provider_discovery_from_contamination(): void
    {
        $this->campaign('cmp-live');

        $sandbox = $this->campaign('sbx-cmp-1');
        $sandbox->forceFill(['raw' => ['sandbox' => true]])->save();

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat'])
            ->expectsOutputToContain('provider campaigns=1')
            ->expectsOutputToContain('SANDBOX-CONTAMINATED rows=1  stored total=2')
            ->assertSuccessful();
    }

    /**
     * SANDBOX-PROD-001 §3 — the cleanup reports before it changes anything, and changes nothing
     * without being told to.
     *
     * It operates on production data that includes four years of somebody's advertising history, so
     * a dry run is the default and the counts are printed either way. A cleanup whose effect nobody
     * can see beforehand is one nobody can approve.
     */
    public function test_the_quarantine_reports_without_deleting_by_default(): void
    {
        $this->campaign('cmp-live');
        $this->campaign('sbx-cmp-1')->forceFill(['raw' => ['sandbox' => true]])->save();

        $this->artisan('integrations:quarantine-sandbox')
            ->expectsOutputToContain('dry run, nothing will change')
            ->expectsOutputToContain('contaminated 1 of 2 stored campaign(s)')
            ->expectsOutputToContain('sandbox rows removed                : 0')
            ->assertSuccessful();

        $this->assertSame(2, ExternalCampaign::withoutGlobalScopes()->count(), 'A dry run deleted a row.');
    }

    /** ...and on --apply it removes the marked rows and leaves the real one alone. */
    public function test_the_quarantine_removes_only_the_marked_rows(): void
    {
        $this->campaign('cmp-live');
        $this->campaign('sbx-cmp-1')->forceFill(['raw' => ['sandbox' => true]])->save();

        $this->artisan('integrations:quarantine-sandbox', ['--apply' => true])
            ->expectsOutputToContain('sandbox rows removed                : 1')
            ->assertSuccessful();

        $remaining = ExternalCampaign::withoutGlobalScopes()->pluck('external_id')->all();

        $this->assertSame(['cmp-live'], $remaining, 'The live campaign must survive the cleanup.');
    }

    /**
     * A campaign NAMED like a sandbox row but carrying no marker is left alone.
     *
     * The whole identification rule: a provider is entitled to name a real campaign anything it
     * likes, so a prefix is a guess about a string and the marker is what the writer wrote. Getting
     * this backwards would delete a customer's campaign for being called the wrong thing.
     */
    public function test_a_campaign_named_like_a_sandbox_row_is_not_touched(): void
    {
        $this->campaign('sbx-cmp-1');

        $this->artisan('integrations:quarantine-sandbox', ['--apply' => true])
            ->expectsOutputToContain('sandbox rows found                  : 0')
            ->assertSuccessful();

        $this->assertSame(1, ExternalCampaign::withoutGlobalScopes()->count());
    }

    /**
     * CONTENT-FIRST-PAGE-MEDIA-001 — «الصور لا تظهر» is a claim about ONE SCREEN, not an estate.
     *
     * The estate census reports 770 of 1,469 creatives holding an image, and that number cannot
     * answer the owner's report: nobody looks at an estate, they look at page one. If the rows the
     * library puts first are the assetless ones, every card on the first screen is blank while the
     * estate reads two-thirds covered, and both facts are true at once.
     *
     * «Drawable» is asked of the PRESENTER's output rather than of the columns, because that is what
     * the browser receives — a stored `asset_url` the presenter withholds is a blank card, and
     * counting the column would call it covered.
     */
    public function test_the_first_page_census_counts_what_the_presenter_would_draw(): void
    {
        $campaign = $this->campaign('cmp-live');

        $this->creativeWith($campaign, 'has-image', asset: 'https://cdn.example/a.jpg');
        $this->creativeWith($campaign, 'has-nothing', asset: null);

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat', '--hierarchy' => true])
            ->expectsOutputToContain('FIRST PAGE — what the twenty-four cards the reader opens on actually hold')
            ->expectsOutputToContain('with something to draw : 1')
            ->expectsOutputToContain('blank                  : 1')
            ->assertSuccessful();
    }

    /**
     * A URL the presenter WITHHOLDS counts as blank, not as covered.
     *
     * This is the whole reason the census asks the presenter: a credential-bearing link is stored,
     * so every column-based count calls the row covered, and the reader still sees nothing.
     */
    public function test_a_withheld_asset_counts_as_blank(): void
    {
        $campaign = $this->campaign('cmp-live');

        $this->creativeWith($campaign, 'withheld', asset: 'https://cdn.example/a.jpg?access_token=SECRET');

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat', '--hierarchy' => true])
            ->expectsOutputToContain('with something to draw : 0')
            ->expectsOutputToContain('blank                  : 1')
            ->assertSuccessful();
    }

    /** A clean account prints no contamination line at all — the finding must stay a finding. */
    public function test_a_clean_account_prints_no_contamination_line(): void
    {
        $this->campaign('cmp-live');

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat'])
            ->expectsOutputToContain('provider campaigns=1')
            ->doesntExpectOutputToContain('SANDBOX-CONTAMINATED')
            ->assertSuccessful();
    }

    /** And an account with none says zero, so «1» is a finding rather than the only sentence it has. */
    public function test_an_account_with_no_sandbox_rows_reports_zero(): void
    {
        $this->campaign('cmp-live');

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat', '--hierarchy' => true])
            ->expectsOutputToContain('SANDBOX campaigns on this account: 0')
            ->assertSuccessful();
    }

    /**
     * AGGREGATION-TRUTH-001 — the PROJECT's rows, including those under an account nobody walks.
     *
     * `ContributorCoverage::expectedProviders()` is PROJECT-scoped: it reads every campaign carrying
     * the project id, whatever account owns it and whether or not that account is still bound. A
     * campaign KEEPS its `project_id` after a binding is removed, so a row under an unlinked account
     * still decides which platforms a client's report expects to hear from — while every
     * account-scoped report in this command walks straight past it.
     *
     * Three repairs of a live client-facing defect failed on exactly this. All 115 rows under the
     * project's three BOUND accounts claim their own platform; the `sandbox` the coverage expects is
     * under something else. Walking accounts cannot close that — the estate holds 619 Snapchat
     * accounts — so the project is asked directly.
     */
    public function test_the_project_view_counts_rows_under_an_account_that_is_not_bound(): void
    {
        $this->campaign('cmp-1');

        /*
         * The account is deliberately a DIFFERENT platform from the one this run walks.
         *
         * `--provider=snapchat` never opens a Meta account, so the row below is reachable ONLY by
         * asking the project. An earlier version put it under a second SNAPCHAT account and the
         * guard was vacuous: the loop opened that account anyway, so account-scoped counting found
         * the row too, and replacing the project filter with an account filter still passed.
         */
        $unbound = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $this->account->provider_connection_id,
            'provider' => 'meta',
            'account_type' => 'ad_account',
            'external_id' => 'act_unbound',
            'name' => 'Unbound',
            'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);

        $stray = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $unbound->id,
            'provider' => 'sandbox',
            'external_id' => 'stray-1',
            'name' => 'stray-1',
            'status' => 'active',
        ]);

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat'])
            ->expectsOutputToContain('the whole PROJECT by provider')
            ->expectsOutputToContain('sandbox            1   0 flagged `raw->sandbox`')
            ->assertSuccessful();

        unset($stray);
    }

    /**
     * AGGREGATION-TRUTH-001 — a single-provider account still reports what its rows claim.
     *
     * The distribution used to print only when an account held more than one provider or carried a
     * flagged row. An account whose campaigns ALL claim some OTHER platform therefore printed
     * nothing at all, which reads exactly like «nothing to see» — and SANDBOX-PROD-001 is that
     * shape: a Meta binding writing `sandbox` campaigns into a live account.
     *
     * Two repairs of a live client-facing defect were written against inferred row shapes, and both
     * failed in Production, because this was the report that could have shown the real one.
     */
    public function test_an_account_whose_rows_all_claim_another_platform_still_reports_it(): void
    {
        $campaign = $this->campaign('cmp-1');
        $campaign->forceFill(['provider' => 'sandbox'])->save();

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat', '--hierarchy' => true])
            ->expectsOutputToContain('campaigns by the provider the ROW claims')
            ->expectsOutputToContain('sandbox            1   0 flagged `raw->sandbox`')
            ->assertSuccessful();
    }

    /**
     * AGGREGATION-TRUTH-001 — the provider a ROW claims, counted beside whether it is flagged.
     *
     * `ContributorCoverage::expectedProviders()` reads `external_campaigns.provider`, and that column
     * alone decides which platforms a project's coverage expects to hear from. It is what makes the
     * owner's live report declare itself `partial` for a «provider» called `sandbox` — and no report
     * printed it, so a fix aimed at rows flagged `raw->sandbox` was written, deployed, and changed
     * nothing, because nothing could say whether those were the same rows.
     *
     * The two cases demand opposite fixes and used to print identically:
     *
     *   sandbox 2, of which 2 flagged  → the flag is the handle; filter on it
     *   sandbox 2, of which 0 flagged  → the flag is irrelevant; the provider value is the problem
     *
     * So this plants one of each and proves the report tells them apart.
     */
    public function test_the_provider_a_row_claims_is_counted_beside_whether_it_is_flagged(): void
    {
        $campaign = $this->campaign('cmp-1');

        /* A flagged contaminated row — what the quarantine and the contamination count both find. */
        $flagged = $this->campaign('sbx-flagged');
        $flagged->forceFill(['provider' => 'sandbox', 'raw' => ['sandbox' => true]])->save();

        /* And one claiming the same provider with NO flag — invisible to every existing report. */
        $unflagged = $this->campaign('sbx-unflagged');
        $unflagged->forceFill(['provider' => 'sandbox', 'raw' => ['id' => 'sbx-unflagged']])->save();

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat'])
            ->expectsOutputToContain('campaigns by the provider the ROW claims')
            ->expectsOutputToContain('sandbox            2   1 flagged `raw->sandbox`')
            ->expectsOutputToContain('snapchat           1   0 flagged `raw->sandbox`')
            ->assertSuccessful();

        unset($campaign);
    }

    /**
     * CONTENT-PREVIEW-SHAPES-001 — the shapes report names the one that draws nothing.
     *
     * Owner ledger row 7: a collection ad is a hero over a grid of product tiles, the tiles live
     * behind a call this product does not make, and the card says so honestly. What no operator
     * could ask until now is whether the live estate holds any — the hierarchy counted 1,451
     * creatives and could not tell a drawable one from a shape with no picture at all.
     *
     * Two creatives, same account, same everything except the shape and the asset: one collection
     * with no link of any kind, one image with one. A report that printed a single total, or that
     * counted every creative as empty, fails both halves.
     */
    public function test_the_shape_that_draws_nothing_is_named_beside_the_one_that_draws(): void
    {
        $campaign = $this->campaign('cmp-1');

        $this->creativeWith($campaign, 'cr-drawable', 'https://cdn.example/still.jpg');

        $collection = $this->creativeWith($campaign, 'cr-collection', null);
        $collection->forceFill(['format' => 'collection'])->save();

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat', '--hierarchy' => true])
            ->expectsOutputToContain('CREATIVE SHAPES')
            ->expectsOutputToContain('collection            :     1   1 carry no asset link of any kind')
            ->expectsOutputToContain('image                 :     1   all carry an asset link')
            ->assertSuccessful();
    }

    /**
     * The vacuity check for the line above: a shape whose rows all carry an asset must NOT be
     * reported as drawing nothing. A report that says «carries no asset link» about everything
     * would satisfy the test above and be worthless.
     */
    public function test_a_shape_whose_rows_all_carry_an_asset_is_not_called_empty(): void
    {
        $campaign = $this->campaign('cmp-1');

        $this->creativeWith($campaign, 'cr-a', 'https://cdn.example/a.jpg');
        $this->creativeWith($campaign, 'cr-b', 'https://cdn.example/b.jpg');

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat', '--hierarchy' => true])
            ->expectsOutputToContain('image                 :     2   all carry an asset link')
            ->assertSuccessful();

        $this->artisan('integrations:diagnose', ['--provider' => 'snapchat', '--hierarchy' => true])
            ->doesntExpectOutputToContain('carry no asset link of any kind')
            ->assertSuccessful();
    }

    private function creativeWith(ExternalCampaign $campaign, string $externalId, ?string $asset): ExternalCreative
    {
        return ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_campaign_id' => $campaign->id,
            'provider' => 'snapchat',
            'external_creative_id' => $externalId,
            'name' => $externalId,
            'format' => 'image',
            'asset_url' => $asset,
            'source_type' => 'api',
        ]);
    }

    private function campaign(string $externalId): ExternalCampaign
    {
        return ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $this->account->id,
            'provider' => 'snapchat',
            'external_id' => $externalId,
            'name' => "Campaign {$externalId}",
            'status' => 'active',
        ]);
    }

    private function adSet(string $externalId, ?ExternalCampaign $campaign): ExternalAdSet
    {
        return ExternalAdSet::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_campaign_id' => $campaign?->id,
            'provider' => 'snapchat',
            'external_id' => $externalId,
            'name' => "Squad {$externalId}",
            'status' => 'active',
        ]);
    }

    private function ad(string $externalId, ?ExternalAdSet $adSet, ?ExternalCampaign $campaign): ExternalAd
    {
        return ExternalAd::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_ad_set_id' => $adSet?->id,
            'external_campaign_id' => $campaign?->id,
            'provider' => 'snapchat',
            'external_id' => $externalId,
            'name' => "Ad {$externalId}",
            'status' => 'active',
        ]);
    }

    /**
     * The creative, and — when an ad carries it — the CANONICAL link from that ad to it.
     *
     * `external_ads.creative_id` is the relation the report reads. A fixture that set only
     * `external_creatives.external_ad_id` would be exercising the column this ticket removed from
     * the diagnosis, and would pass or fail for reasons unrelated to what is being tested.
     */
    private function creative(string $externalId, ?ExternalAd $ad, ?ExternalCampaign $campaign): ExternalCreative
    {
        $creative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_campaign_id' => $campaign?->id,
            'provider' => 'snapchat',
            'external_creative_id' => $externalId,
            'name' => "Creative {$externalId}",
            'format' => 'image',
        ]);

        $ad?->forceFill(['creative_id' => $creative->getKey()])->save();

        return $creative;
    }

    /**
     * One real day of creative figures, plus the sort column the library orders on.
     *
     * `last_active_at` is written here rather than derived, because the ingest that normally sets it
     * is not what these tests are exercising — the read is.
     */
    private function figures(
        ExternalCreative $creative,
        Carbon $date,
        float $spend,
        int $impressions,
        int $clicks,
    ): void {
        DB::table('creative_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'creative_id' => $creative->getKey(),
            'metric_date' => $date->toDateString(),
            'spend' => $spend,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $creative->forceFill(['last_active_at' => $date])->save();
    }

    /**
     * A day of AD-grain figures — the evidence that the platform reported the ad.
     *
     * Deliberately NOT copied down onto the creative: the census counts this as a fact about the ad,
     * and projecting it would manufacture exactly the creative figures this whole investigation is
     * trying to find honestly.
     */
    private function adFigures(ExternalAd $ad, Carbon $date): void
    {
        DB::table('entity_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'entity_type' => 'ad',
            'entity_id' => $ad->getKey(),
            'external_entity_id' => $ad->external_id,
            'provider' => 'snapchat',
            'metric_date' => $date->toDateString(),
            'impressions' => 100,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    /** One recorded metrics run, with whatever meta the test needs it to carry. */
    private function metricRun(Carbon $startedAt, array $meta): void
    {
        DB::table('metric_sync_runs')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $this->account->getKey(),
            'provider' => 'snapchat',
            'status' => 'success',
            // NOT NULL on this table — a run is always ABOUT a window.
            'window_start' => $startedAt->toDateString(),
            'window_end' => $startedAt->toDateString(),
            'started_at' => $startedAt,
            'finished_at' => $startedAt,
            'meta' => json_encode($meta),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }
}
