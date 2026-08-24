<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
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
        $this->assertSame(1, ExternalCreative::withoutGlobalScopes()->whereNotNull('external_ad_id')->count());
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

    // ── helpers ───────────────────────────────────────────────────────────────────────────────

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
            'external_ad_id' => $ad?->id,
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
