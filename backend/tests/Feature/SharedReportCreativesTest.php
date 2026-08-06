<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\CreativeGroup;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Reports\Support\CreativeVisibility;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * §15.12 — the creative sections of a client's report, and the ways a link could show too much.
 *
 * This is the one surface in the product reachable with no session at all. Everywhere else, a
 * mistake in a ceiling is visible to one company; here it is visible to whoever was sent the link.
 * So the tests are almost entirely about REFUSAL: what a tampered URL, a guessed id, a widened
 * filter and an export must not produce.
 *
 * The positive claims are the two that make the section trustworthy at all — it is the library's own
 * selection, and its figures are the library's own figures.
 */
final class SharedReportCreativesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $awareness;

    private UnifiedCampaign $sales;

    private Report $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Share', 'slug' => 'share-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());

        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => 'P', 'status' => 'active',
        ]);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->awareness = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $client->getKey(), 'name' => 'Brand',
            'objective' => 'awareness', 'status' => 'active',
        ]);
        $this->sales = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $client->getKey(), 'name' => 'Sale',
            'objective' => 'sales', 'status' => 'active',
        ]);

        $this->report = Report::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'Monthly', 'type' => 'performance', 'status' => 'completed',
            'audience' => 'client', 'currency' => 'SAR', 'form' => 'detailed',
            'period_start' => now()->subDays(60)->toDateString(),
            'period_end' => now()->toDateString(),
            'data' => ['period' => ['from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString()]],
            'generated_at' => now(),
        ]);
    }

    // ---- fixtures -----------------------------------------------------------------------------

    private function creative(array $over = []): ExternalCreative
    {
        return ExternalCreative::create(array_merge([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'campaign_id' => $this->sales->getKey(),
            'provider' => 'meta',
            'external_creative_id' => 'cr-'.Str::random(8),
            'name' => 'A creative',
            'format' => 'image',
            'status' => 'active',
            'headline' => 'A headline',
            'body' => 'Some ad copy',
            'cta' => 'SHOP_NOW',
            'destination_url' => 'https://example.test/product',
            'asset_url' => 'https://cdn.example.test/a.jpg',
            'last_active_at' => now(),
            'last_synced_at' => now(),
        ], $over));
    }

    private function figures(ExternalCreative $creative, array $values, int $daysAgo = 2): void
    {
        DB::table('creative_daily_metrics')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'creative_id' => $creative->getKey(),
            'campaign_id' => $creative->campaign_id,
            'metric_date' => now()->subDays($daysAgo)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ], $values));
    }

    /** Everything a client may see, so a test can turn ONE thing off and watch it disappear. */
    private function everything(array $over = []): array
    {
        return array_merge([
            'creatives' => true, 'video' => true, 'image_zoom' => true, 'download' => true,
            'ad_copy' => true, 'headline' => true, 'cta' => true, 'destination_url' => true,
            'comparison' => true, 'spend' => true, 'revenue' => true, 'cpa' => true, 'roas' => true,
            'insights' => true, 'recommendations' => true,
        ], $over);
    }

    /** @return array{0: string, 1: ReportShare} the raw token and the share */
    private function link(array $creatives = [], array $scope = [], array $opts = []): array
    {
        [$share, $raw] = app(ShareService::class)->create($this->report, array_merge([
            // Explicit, the way the operator's form sends it — a scope is no longer a request to be
            // live now that it also carries which creatives a link may show.
            'mode' => 'snapshot',
            'settings' => ['creatives' => CreativeVisibility::fromArray($creatives)->toArray()],
            'scope' => array_merge([
                'project_id' => (string) $this->project->getKey(),
                'campaign_ids' => [(string) $this->awareness->getKey(), (string) $this->sales->getKey()],
                'providers' => ['meta', 'tiktok'],
                'earliest' => now()->subDays(30)->toDateString(),
                'latest' => now()->toDateString(),
            ], $scope),
        ], $opts), null);

        return [$raw, $share];
    }

    private function open(string $token, string $path = '', string $query = ''): TestResponse
    {
        return $this->getJson("/api/v1/reports/shared/{$token}{$path}{$query}");
    }

    // ---- it is the library's own selection ----------------------------------------------------

    /** The section answers with the same creatives and the same figures the operator's library holds. */
    public function test_the_client_reads_the_same_creatives_and_the_same_figures(): void
    {
        $creative = $this->creative(['name' => 'Hero image']);
        $this->figures($creative, ['spend' => 1200, 'impressions' => 60000, 'clicks' => 1800, 'conversions' => 60, 'revenue' => 9000]);

        [$token] = $this->link($this->everything());

        $rows = $this->open($token, '/creatives')->assertOk()->json('data.creatives');

        $this->assertCount(1, $rows);
        $this->assertSame('Hero image', $rows[0]['name']);
        $this->assertEqualsWithDelta(1200.0, $rows[0]['metrics']['spend'], 0.0001);
        $this->assertEqualsWithDelta(7.5, $rows[0]['metrics']['roas'], 0.0001);
        $this->assertSame('sales', $rows[0]['objective']);
        $this->assertSame('conversion', $rows[0]['path']);
    }

    /** The summary ranks inside each path, and never declares one winner across them. */
    public function test_the_summary_ranks_each_objective_on_its_own_metric(): void
    {
        $reach = $this->creative(['name' => 'Reach film', 'campaign_id' => $this->awareness->getKey(), 'format' => 'video']);
        $sale = $this->creative(['name' => 'Sale image']);
        $this->figures($reach, ['spend' => 500, 'impressions' => 400000, 'clicks' => 800]);
        $this->figures($sale, ['spend' => 500, 'impressions' => 40000, 'clicks' => 900, 'conversions' => 50, 'revenue' => 5000]);

        [$token] = $this->link($this->everything());

        $best = collect($this->open($token, '/creatives/summary')->assertOk()->json('data.best_by_objective'))
            ->keyBy('objective');

        $this->assertSame('cpm', $best['awareness']['metric']);
        $this->assertSame('roas', $best['sales']['metric']);
    }

    // ---- fail-closed --------------------------------------------------------------------------

    /** A link that says nothing about creatives shows none — including every link made before this. */
    public function test_a_link_with_no_creative_settings_shows_no_creatives(): void
    {
        $creative = $this->creative();
        $this->figures($creative, ['spend' => 100, 'impressions' => 20000, 'clicks' => 400]);

        [$token] = $this->link();  // no visibility at all

        $this->open($token, '/creatives')->assertNotFound();
        $this->open($token, '/creatives/summary')->assertNotFound();
        $this->open($token, '/creatives/'.$creative->getKey())->assertNotFound();
    }

    /**
     * An excluded creative does not open — not in the list, not by its own id, not by its group's.
     *
     * The whole point of the ceiling, tested the way it will actually be attacked: by pasting an id
     * into the address bar.
     */
    public function test_an_excluded_creative_cannot_be_reached_by_any_address(): void
    {
        $shown = $this->creative(['name' => 'Shown']);
        $hidden = $this->creative(['name' => 'Hidden']);
        $this->figures($shown, ['spend' => 100, 'impressions' => 20000, 'clicks' => 400]);
        $this->figures($hidden, ['spend' => 900, 'impressions' => 90000, 'clicks' => 900]);

        [$token] = $this->link($this->everything(), [
            'excluded_creative_ids' => [(string) $hidden->getKey()],
        ]);

        $rows = $this->open($token, '/creatives')->assertOk()->json('data.creatives');
        $this->assertSame(['Shown'], array_column($rows, 'name'));

        // By id, directly. 404 rather than 403: a 403 confirms the id exists and is merely withheld.
        $this->open($token, '/creatives/'.$hidden->getKey())->assertNotFound();

        // Through the comparison, alongside one that IS shared.
        $this->open($token, '/creatives/comparison', '?creative_ids[]='.$shown->getKey().'&creative_ids[]='.$hidden->getKey())
            ->assertNotFound();

        // And nowhere in the summary's rankings.
        $summary = $this->open($token, '/creatives/summary')->assertOk()->json('data');
        $this->assertStringNotContainsString('Hidden', json_encode($summary, JSON_UNESCAPED_UNICODE));
    }

    /** A named allow-list is a ceiling, and a creative outside it is outside every section. */
    public function test_only_the_named_creatives_and_groups_are_reachable(): void
    {
        $named = $this->creative(['name' => 'Named']);
        $grouped = $this->creative(['name' => 'In the group']);
        $neither = $this->creative(['name' => 'Neither']);

        $group = CreativeGroup::create([
            'project_id' => $this->project->getKey(), 'name' => 'G', 'method' => 'manual',
        ]);
        $grouped->forceFill(['creative_group_id' => $group->getKey()])->save();

        foreach ([$named, $grouped, $neither] as $c) {
            $this->figures($c, ['spend' => 100, 'impressions' => 20000, 'clicks' => 400]);
        }

        [$token] = $this->link($this->everything(), [
            'creative_ids' => [(string) $named->getKey()],
            'creative_group_ids' => [(string) $group->getKey()],
        ]);

        $rows = $this->open($token, '/creatives')->assertOk()->json('data.creatives');
        $names = array_column($rows, 'name');
        sort($names);

        // A UNION of the two allow-lists, not an intersection — the operator picked both, meaning
        // «this creative AND everything in that group».
        $this->assertSame(['In the group', 'Named'], $names);
        $this->assertNotFound(...[$this->open($token, '/creatives/'.$neither->getKey())]);
    }

    /**
     * The reader's filters may only narrow.
     *
     * A platform outside the ceiling is dropped from the request rather than honoured, and asking for
     * ONLY forbidden platforms falls back to the ceiling — never to «no filter», which is how a
     * fail-closed filter turns into an open one.
     */
    public function test_a_query_string_cannot_widen_the_ceiling(): void
    {
        $inside = $this->creative(['name' => 'Meta', 'provider' => 'meta']);
        $outside = $this->creative(['name' => 'Snapchat', 'provider' => 'snapchat']);
        $this->figures($inside, ['spend' => 100, 'impressions' => 20000, 'clicks' => 400]);
        $this->figures($outside, ['spend' => 100, 'impressions' => 20000, 'clicks' => 400]);

        [$token] = $this->link($this->everything(), ['providers' => ['meta']]);

        // Asking for the forbidden platform explicitly.
        $rows = $this->open($token, '/creatives', '?providers[]=snapchat')->assertOk()->json('data.creatives');
        $this->assertSame(['Meta'], array_column($rows, 'name'));

        // Asking for a window outside the granted one clamps to its edge.
        $applied = $this->open($token, '/creatives', '?from=2020-01-01&to=2020-01-31')->assertOk()->json('data.applied');
        $this->assertSame(now()->subDays(30)->toDateString(), $applied['from']);
    }

    /** A link into another tenant's project shows that project nothing. */
    public function test_a_share_cannot_reach_across_tenants_or_projects(): void
    {
        $mine = $this->creative(['name' => 'Mine']);
        $this->figures($mine, ['spend' => 100, 'impressions' => 20000, 'clicks' => 400]);

        $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $otherTenant->getKey());
        $otherClient = ClientWorkspace::create([
            'tenant_id' => $otherTenant->getKey(), 'name' => 'OC', 'slug' => 'oc-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $otherProject = Project::create([
            'tenant_id' => $otherTenant->getKey(), 'client_workspace_id' => $otherClient->getKey(),
            'name' => 'OP', 'status' => 'active',
        ]);
        $theirs = ExternalCreative::create([
            'tenant_id' => $otherTenant->getKey(), 'project_id' => $otherProject->getKey(),
            'provider' => 'meta', 'external_creative_id' => 'cr-other', 'name' => 'Theirs',
            'format' => 'image', 'status' => 'active', 'last_synced_at' => now(),
        ]);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());

        [$token] = $this->link($this->everything());

        $rows = $this->open($token, '/creatives')->assertOk()->json('data.creatives');
        $this->assertSame(['Mine'], array_column($rows, 'name'));

        // Naming the other tenant's creative by id, and pointing the project filter at their project.
        $this->open($token, '/creatives/'.$theirs->getKey())->assertNotFound();
        $rows = $this->open($token, '/creatives', '?project_ids[]='.$otherProject->getKey())
            ->assertOk()->json('data.creatives');
        $this->assertSame(['Mine'], array_column($rows, 'name'));
    }

    // ---- the visibility switches --------------------------------------------------------------

    /** Hidden money is absent from the payload, not hidden by the page. */
    public function test_hidden_spend_and_revenue_are_absent_from_every_shape(): void
    {
        $creative = $this->creative(['name' => 'Quiet']);
        $this->figures($creative, ['spend' => 1200, 'impressions' => 60000, 'clicks' => 1800, 'conversions' => 60, 'revenue' => 9000]);

        [$token] = $this->link($this->everything(['spend' => false, 'revenue' => false]));

        $rows = $this->open($token, '/creatives')->assertOk()->json('data.creatives');
        $metrics = $rows[0]['metrics'];

        foreach (['spend', 'revenue', 'cpc', 'cpm', 'cpa', 'roas', 'aov'] as $key) {
            $this->assertArrayNotHasKey($key, $metrics, "{$key} survived a hidden spend/revenue");
        }
        // Delivery is not money, and hiding a client's own click-through rate serves nobody.
        $this->assertEqualsWithDelta(60000.0, $metrics['impressions'], 0.0001);
        $this->assertEqualsWithDelta(0.03, $metrics['ctr'], 0.0001);

        // The headline list is filtered too, so no labelled empty slot names what is withheld.
        $this->assertNotContains('roas', $rows[0]['headline_metrics']);

        // And in the summary, where the same figures reach a ranking and a spend split.
        $summary = $this->open($token, '/creatives/summary')->assertOk()->json('data');
        $this->assertNull($summary['spend_by_kind'][0]['spend']);
        $this->assertNull($summary['spend_by_kind'][0]['share']);
        $this->assertNull($summary['fatigue']['spend_at_risk']);
    }

    /**
     * ROAS cannot be shown while spend is hidden, however the operator ticks the boxes.
     *
     * Return on spend is revenue over spend. A link showing revenue and ROAS while hiding spend has
     * published the spend — one division away, from two figures printed beside each other.
     */
    public function test_roas_cannot_survive_a_hidden_spend(): void
    {
        $creative = $this->creative();
        $this->figures($creative, ['spend' => 1200, 'impressions' => 60000, 'clicks' => 1800, 'conversions' => 60, 'revenue' => 9000]);

        [$token] = $this->link($this->everything(['spend' => false]));

        $body = $this->open($token, '/creatives')->assertOk()->json('data');

        $this->assertFalse($body['permissions']['roas'], 'ROAS must resolve to hidden when spend is');
        $this->assertFalse($body['permissions']['cpa']);
        $this->assertArrayNotHasKey('roas', $body['creatives'][0]['metrics']);
    }

    /** Ad copy, headline, CTA and the destination URL each disappear on their own switch. */
    public function test_the_copy_fields_are_withheld_one_by_one(): void
    {
        $creative = $this->creative(['name' => 'Wordy']);
        $this->figures($creative, ['spend' => 100, 'impressions' => 20000, 'clicks' => 400]);

        [$open] = $this->link($this->everything());
        $shown = $this->open($open, '/creatives/'.$creative->getKey())->assertOk()->json('data.creative');

        $this->assertSame('A headline', $shown['copy']['headline']);
        $this->assertSame('Some ad copy', $shown['copy']['body']);
        $this->assertSame('SHOP_NOW', $shown['copy']['cta']);
        $this->assertSame('https://example.test/product', $shown['destination_url']);

        [$closed] = $this->link($this->everything([
            'ad_copy' => false, 'headline' => false, 'cta' => false, 'destination_url' => false,
        ]));
        $hidden = $this->open($closed, '/creatives/'.$creative->getKey())->assertOk()->json('data.creative');

        $this->assertArrayNotHasKey('copy', $hidden);
        $this->assertArrayNotHasKey('destination_url', $hidden);
        // Platform record ids are never a client's business, whatever else is permitted.
        $this->assertArrayNotHasKey('external_ids', $hidden);
    }

    /** A link that forbids video playback carries no video URL for a player to find. */
    public function test_a_forbidden_video_has_no_url_in_the_payload(): void
    {
        $creative = $this->creative([
            'name' => 'Film', 'format' => 'video',
            'video_url' => 'https://cdn.example.test/a.mp4',
        ]);
        $this->figures($creative, ['spend' => 100, 'impressions' => 20000, 'clicks' => 400]);

        [$allowed] = $this->link($this->everything());
        $this->assertSame(
            'https://cdn.example.test/a.mp4',
            $this->open($allowed, '/creatives')->json('data.creatives.0.preview.video_url'),
        );

        [$refused] = $this->link($this->everything(['video' => false]));
        $row = $this->open($refused, '/creatives')->json('data.creatives.0');

        $this->assertNull($row['preview']['video_url']);
        // Playback and zoom are separate switches: refusing one leaves the other alone.
        $this->assertTrue($row['preview']['can_zoom']);

        [$noZoom] = $this->link($this->everything(['image_zoom' => false, 'download' => false]));
        $row = $this->open($noZoom, '/creatives')->json('data.creatives.0');

        $this->assertFalse($row['preview']['can_zoom']);
        $this->assertFalse($row['preview']['can_download']);
    }

    /** Comparison is refused outright when the link does not carry it. */
    public function test_comparison_is_refused_when_it_is_not_permitted(): void
    {
        $a = $this->creative(['name' => 'A']);
        $b = $this->creative(['name' => 'B']);
        $this->figures($a, ['spend' => 100, 'impressions' => 20000, 'clicks' => 400]);
        $this->figures($b, ['spend' => 100, 'impressions' => 20000, 'clicks' => 800]);

        $query = '?creative_ids[]='.$a->getKey().'&creative_ids[]='.$b->getKey();

        [$open] = $this->link($this->everything());
        $this->open($open, '/creatives/comparison', $query)->assertOk();

        [$closed] = $this->link($this->everything(['comparison' => false]));
        $this->open($closed, '/creatives/comparison', $query)->assertNotFound();
    }

    /** An insight whose evidence is withheld is dropped, not shown as an unverifiable assertion. */
    public function test_an_insight_without_its_evidence_is_dropped(): void
    {
        $creative = $this->creative(['name' => 'Costly']);
        $this->figures($creative, ['spend' => 500, 'impressions' => 60000, 'clicks' => 1800, 'conversions' => 90, 'revenue' => 9000], 35);
        $this->figures($creative, ['spend' => 1500, 'impressions' => 60000, 'clicks' => 1800, 'conversions' => 30, 'revenue' => 3000], 2);

        [$open] = $this->link($this->everything(), ['earliest' => now()->subDays(29)->toDateString()]);
        $keys = array_column($this->open($open, '/creatives/summary')->json('data.insights.items'), 'key');
        $this->assertContains('cpa_increase', $keys);

        [$closed] = $this->link(
            $this->everything(['spend' => false]),
            ['earliest' => now()->subDays(29)->toDateString()],
        );
        $body = $this->open($closed, '/creatives/summary')->json('data.insights');

        $this->assertNotContains('cpa_increase', array_column($body['items'], 'key'));
        // The honest count survives: the reader is told some were withheld, not told there were none.
        $this->assertGreaterThan(count($body['items']), $body['total']);
    }

    /** Recommendations off removes the action, and keeps the finding it was attached to. */
    public function test_recommendations_can_be_withheld_while_the_finding_stays(): void
    {
        $creative = $this->creative(['name' => 'Fading']);
        $this->figures($creative, ['spend' => 1000, 'impressions' => 100000, 'clicks' => 3000], 35);
        $this->figures($creative, ['spend' => 1000, 'impressions' => 100000, 'clicks' => 2000], 2);

        [$token] = $this->link(
            $this->everything(['recommendations' => false]),
            ['earliest' => now()->subDays(29)->toDateString()],
        );

        $item = collect($this->open($token, '/creatives/summary')->json('data.insights.items'))
            ->firstWhere('key', 'ctr_decline');

        $this->assertNotNull($item);
        $this->assertArrayNotHasKey('action_ar', $item);
        $this->assertArrayNotHasKey('action_en', $item);
        $this->assertNotSame('', $item['detail_en']);
    }

    // ---- form and mode ------------------------------------------------------------------------

    /**
     * The FORM and the MODE are two facts, and one link may differ from another on either.
     *
     * Four combinations, all valid. Before this, `form` came from the report row — so two links to
     * one report could not differ — and `mode` was derived from whether a scope existed, so choosing
     * which creatives a link may show would have silently turned a snapshot live.
     */
    public function test_summary_and_detailed_links_differ_and_stay_independent_of_live_and_snapshot(): void
    {
        $creative = $this->creative();
        $this->figures($creative, ['spend' => 100, 'impressions' => 20000, 'clicks' => 400]);

        [$summary] = $this->link($this->everything(), [], ['form' => 'executive_summary']);
        [$detailed] = $this->link($this->everything(), [], ['form' => 'detailed', 'mode' => 'live']);

        $a = $this->open($summary)->assertOk()->json('data');
        $b = $this->open($detailed)->assertOk()->json('data');

        $this->assertSame('executive_summary', $a['form']);
        $this->assertSame('snapshot', $a['mode'], 'a summary link is not forced live by having a scope');

        $this->assertSame('detailed', $b['form']);
        $this->assertSame('live', $b['mode']);
    }

    // ---- exports ------------------------------------------------------------------------------

    /**
     * A withheld figure is not in the FILE either.
     *
     * The failure this prevents is the worst-shaped one in the feature: a page that correctly hides
     * a number beside a download button that hands over a spreadsheet containing it.
     */
    public function test_a_download_carries_no_figure_the_link_withholds(): void
    {
        $creative = $this->creative(['name' => 'Exported']);
        $this->figures($creative, ['spend' => 1234.5, 'impressions' => 60000, 'clicks' => 1800, 'conversions' => 60, 'revenue' => 9876.5]);

        [$open] = $this->link($this->everything());
        $withMoney = $this->open($open, '/download/csv');
        $withMoney->assertOk();
        $csv = $withMoney->streamedContent();

        $this->assertStringContainsString('Exported', $csv);
        $this->assertStringContainsString('1234.5', $csv);

        [$closed] = $this->link($this->everything(['spend' => false, 'revenue' => false]));
        $csv = $this->open($closed, '/download/csv')->assertOk()->streamedContent();

        $this->assertStringContainsString('Exported', $csv);
        $this->assertStringNotContainsString('1234.5', $csv);
        $this->assertStringNotContainsString('9876.5', $csv);
    }

    /** With creatives off entirely, the export carries no creative row at all. */
    public function test_a_download_carries_no_creatives_when_the_link_shows_none(): void
    {
        $creative = $this->creative(['name' => 'Unshared']);
        $this->figures($creative, ['spend' => 100, 'impressions' => 20000, 'clicks' => 400]);

        [$token] = $this->link();

        $csv = $this->open($token, '/download/csv')->assertOk()->streamedContent();

        $this->assertStringNotContainsString('Unshared', $csv);
    }

    // ---- the other gates still apply ----------------------------------------------------------

    /** A revoked link stops answering about creatives too, not only about the report. */
    public function test_a_revoked_link_answers_nothing(): void
    {
        $creative = $this->creative();
        $this->figures($creative, ['spend' => 100, 'impressions' => 20000, 'clicks' => 400]);

        [$token, $share] = $this->link($this->everything());
        $this->open($token, '/creatives')->assertOk();

        $share->update(['revoked_at' => now()]);

        $this->open($token, '/creatives')->assertNotFound();
        $this->open($token, '/creatives/summary')->assertNotFound();
        $this->open($token, '/creatives/'.$creative->getKey())->assertNotFound();
    }

    /** A password-protected link asks for the password on the creative endpoints as well. */
    public function test_the_password_gate_covers_the_creative_endpoints(): void
    {
        $creative = $this->creative();
        $this->figures($creative, ['spend' => 100, 'impressions' => 20000, 'clicks' => 400]);

        [$token] = $this->link($this->everything(), [], ['password' => 'letmein']);

        $this->open($token, '/creatives')->assertStatus(401);
        $this->open($token, '/creatives/summary')->assertStatus(401);
        $this->open($token, '/creatives', '?password=letmein')->assertOk();
    }

    /**
     * §15.6 — the client's creative page gets the same funnel, minus what the link withholds.
     *
     * The stages and their conversion rates are the client's own funnel and stay. The cost per stage
     * does not: it is the spend divided by a count printed beside it, so leaving it in while hiding
     * the spend row would publish the budget to anyone willing to multiply.
     */
    public function test_the_shared_funnel_keeps_its_stages_and_drops_the_cost_when_spend_is_hidden(): void
    {
        $creative = $this->creative(['name' => 'Funnelled']);
        $this->figures($creative, [
            'spend' => 1000, 'impressions' => 50000, 'clicks' => 1000,
            'add_to_cart' => 200, 'purchases' => 50,
        ]);

        [$open] = $this->link($this->everything());
        [$closed] = $this->link($this->everything(['spend' => false, 'cpa' => false, 'roas' => false]));

        $id = (string) $creative->getKey();

        $shown = $this->open($open, "/creatives/{$id}")->assertOk()->json('data.funnel');
        $this->assertSame(['impressions', 'clicks', 'add_to_cart', 'purchases'], array_column($shown['stages'], 'key'));
        $this->assertEqualsWithDelta(20.0, (float) $shown['stages'][3]['cost_per'], 0.0001);

        $hidden = $this->open($closed, "/creatives/{$id}")->assertOk()->json('data.funnel');
        $this->assertSame(array_column($shown['stages'], 'key'), array_column($hidden['stages'], 'key'));
        foreach ($hidden['stages'] as $stage) {
            $this->assertNull($stage['cost_per'], 'a per-stage cost survived a hidden spend');
            $this->assertTrue($stage['cost_hidden']);
        }
        // The conversion rates are untouched — none of them reconstructs the money.
        $this->assertEqualsWithDelta(0.2, (float) $hidden['stages'][2]['rate_from_previous'], 0.0001);

        // And no stage carries the spend under another name.
        foreach ($hidden['stages'] as $stage) {
            $this->assertArrayNotHasKey('spend', $stage);
        }
    }

    /** @param TestResponse ...$responses */
    private function assertNotFound(...$responses): void
    {
        foreach ($responses as $response) {
            $response->assertNotFound();
        }
    }

    /**
     * A carousel's cards obey the same switches as the creative's own copy.
     *
     * The failure this rules out is not subtle: a link that hides the ad copy and then ships four
     * cards each holding a headline has not hidden the ad copy, it has MOVED it — and the operator
     * who flipped the switch has no way to know. Asserted on the payload, not the rendering, because
     * «hidden by the UI» is the thing §15.12 forbids.
     */
    public function test_a_carousels_cards_are_redacted_by_the_same_switches_as_the_creative(): void
    {
        $creative = $this->creative([
            'name' => 'Bundle carousel',
            'format' => 'carousel',
            'cards' => [
                ['image_url' => 'https://cdn.example.com/a.jpg', 'headline' => 'CARD-HEADLINE', 'body' => 'CARD-BODY', 'cta' => 'CARD-CTA', 'destination_url' => 'https://shop.example.com/CARD-DEST'],
            ],
        ]);
        $this->figures($creative, ['spend' => 300, 'impressions' => 9000]);

        [$open] = $this->link($this->everything());
        [$closed] = $this->link($this->everything([
            'ad_copy' => false, 'headline' => false, 'cta' => false, 'destination_url' => false,
        ]));

        $shown = $this->open($open, '/creatives')->assertOk()->json('data.creatives.0.preview.cards.0');
        $this->assertSame('CARD-HEADLINE', $shown['headline']);
        $this->assertSame('https://shop.example.com/CARD-DEST', $shown['destination_url']);

        $response = $this->open($closed, '/creatives')->assertOk();
        $hidden = $response->json('data.creatives.0.preview.cards.0');

        // The picture is what a carousel IS and it stays; the copy is GONE from the payload.
        $this->assertNotNull($hidden['image_url']);
        $this->assertArrayNotHasKey('headline', $hidden);
        $this->assertArrayNotHasKey('body', $hidden);
        $this->assertArrayNotHasKey('cta', $hidden);
        $this->assertArrayNotHasKey('destination_url', $hidden);
        $response->assertDontSee('CARD-HEADLINE')->assertDontSee('CARD-DEST');
    }

    /** A video card is a video: the link's video switch reaches one level down. */
    public function test_a_video_card_is_silenced_by_the_links_video_switch(): void
    {
        $creative = $this->creative([
            'format' => 'carousel',
            'cards' => [['video_url' => 'https://cdn.example.com/card.mp4', 'thumbnail_url' => 'https://cdn.example.com/card.jpg']],
        ]);
        $this->figures($creative, ['spend' => 120, 'impressions' => 4000]);

        [$token] = $this->link($this->everything(['video' => false]));

        $card = $this->open($token, '/creatives')->assertOk()->json('data.creatives.0.preview.cards.0');

        $this->assertNull($card['video_url'], 'the player was still fed on a link that hides video');
        $this->assertNotNull($card['thumbnail_url']);
    }
}
