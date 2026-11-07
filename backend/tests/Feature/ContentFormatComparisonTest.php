<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Support\CreativeKind;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Services\ContentFormatComparison;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Sections\ReportSectionRegistry;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CONTENT-FORMAT-COMPARISON-001 — «مقارنة أنواع المحتوى», held to the Owner's «دقيقة 100%».
 *
 * Every rule the comparison promises is pinned here against a figure computed by hand: the format is the platform's
 * token and nothing else; ratios are pooled, never averaged; objectives are never mixed; a withheld amount is never
 * zero; a result the platform did not attribute at creative grain is not a result; the verdict is the canonical
 * ranker's, on a cost per result and never on ROAS, and a small sample cannot win; another project never enters; a
 * client reads no spend; and the report part is a SHOW/HIDE of its own across the link, the file and the PDF's data.
 */
final class ContentFormatComparisonTest extends TestCase
{
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    private Tenant $tenant;

    private Project $project;

    private Project $other;

    private ClientWorkspace $workspace;

    private ExternalAccount $meta;

    private ExternalAccount $snap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'cfc-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());

        $this->workspace = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['client_workspace_id' => $this->workspace->getKey(), 'name' => 'P', 'status' => 'active']);
        $otherWorkspace = ClientWorkspace::create(['name' => 'D', 'slug' => 'd-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->other = Project::create(['client_workspace_id' => $otherWorkspace->getKey(), 'name' => 'Q', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->meta = $this->account('meta', 'Meta A');
        $this->snap = $this->account('snapchat', 'Snap B');
        $this->bind($this->meta, $this->project, true);
        $this->bind($this->snap, $this->project, true);
    }

    // ── format: the token, and nothing else ──────────────────────────────────────────────────────────────

    public function test_the_format_is_the_platforms_token_and_an_unknown_token_is_its_own_slice(): void
    {
        $c = $this->campaign('sales');
        $this->creatives($this->meta, $c, 'IMAGE', 2, ['spend' => 50.0, 'conversions' => 5.0]);
        $this->creatives($this->meta, $c, 'video', 2, ['spend' => 50.0, 'conversions' => 5.0]);
        $this->creatives($this->meta, $c, 'collection_video', 2, ['spend' => 25.0, 'conversions' => 1.0]);
        $this->creatives($this->snap, $c, 'story_ad', 1, ['spend' => 30.0, 'conversions' => 3.0]);
        // A film resolved, and the token says nothing a kind names: NOT guessed to be a video.
        $this->creatives($this->snap, $c, 'SNAP_AD', 1, ['spend' => 10.0, 'conversions' => 1.0], ['video_url' => 'https://cdn.example/a.mp4']);
        $this->creatives($this->snap, $c, null, 1, ['spend' => 10.0, 'conversions' => 0.0]);

        $sales = $this->ask()['objectives'][0];
        $formats = collect($sales['formats'])->keyBy('format');

        $this->assertSame(['collection', 'image', 'unclassified', 'video'], $formats->keys()->sort()->values()->all());
        $this->assertSame(3, $formats['unclassified']['creatives']);
        $this->assertSame([['token' => '', 'creatives' => 1], ['token' => 'snap_ad', 'creatives' => 1], ['token' => 'story_ad', 'creatives' => 1]], $formats['unclassified']['tokens']);
        $this->assertSame('unclassified', CreativeKind::fromToken('SNAP_AD'));
        $this->assertSame('video', CreativeKind::of((new ExternalCreative)->forceFill(['format' => 'SNAP_AD', 'video_url' => 'x'])), 'the card may read the asset; the comparison does not');

        // Coverage chip: spend whose format the platform named = (100 + 100 + 50) / (100 + 100 + 50 + 30 + 10 + 10).
        $this->assertEqualsWithDelta(250.0 / 300.0, $sales['coverage']['spend_known_share'], 1e-9);
        // Results whose format is known = (10 + 10 + 2) / (10 + 10 + 2 + 3 + 1 + 0).
        $this->assertEqualsWithDelta(22.0 / 26.0, $sales['coverage']['results_known_share'], 1e-9);
        $this->assertSame(['creatives_total' => 9, 'creatives_known' => 6], array_intersect_key($this->ask()['coverage'], array_flip(['creatives_total', 'creatives_known'])));
        $this->assertNotSame('unclassified', $sales['winner']['format'] ?? null, 'an unclassified slice is never the winner');
    }

    // ── ratios: pooled from sums, never averaged ────────────────────────────────────────────────────────

    public function test_ratios_are_recomputed_from_summed_numerators_and_denominators(): void
    {
        $c = $this->campaign('sales');
        $this->creative($this->meta, $c, 'image', ['spend' => 10.0, 'impressions' => 1000.0, 'clicks' => 10.0, 'conversions' => 1.0]);
        $this->creative($this->meta, $c, 'image', ['spend' => 90.0, 'impressions' => 3000.0, 'clicks' => 30.0, 'conversions' => 30.0]);

        $image = $this->format('sales', 'image');

        $this->assertEqualsWithDelta(round(100 / 31, 4), $image['cost_per_result'], 1e-9, 'CPA is Σspend ÷ Σconversions, not the mean of 10 and 3');
        $this->assertNotEqualsWithDelta(6.5, $image['cost_per_result'], 0.01);
        $this->assertEqualsWithDelta(0.01, $image['ctr'], 1e-9, 'CTR is Σclicks ÷ Σimpressions = 40 / 4000');
        $this->assertEqualsWithDelta(round(100 / 4000 * 1000, 4), $image['cpm'], 1e-9);
        $this->assertEqualsWithDelta(round(100 / 40, 4), $image['cpc'], 1e-9);
        $this->assertEqualsWithDelta(round(31 / 40, 4), $image['conversion_rate'], 1e-9);
        $this->assertEquals(31.0, $image['results']);
    }

    // ── objectives: one comparison each, results never mixed ───────────────────────────────────────────

    public function test_each_objective_is_its_own_comparison_on_its_own_result(): void
    {
        $sales = $this->campaign('sales');
        $traffic = $this->campaign('traffic');
        $this->creatives($this->meta, $sales, 'image', 2, ['spend' => 100.0, 'clicks' => 50.0, 'conversions' => 10.0]);
        $this->creatives($this->meta, $sales, 'video', 2, ['spend' => 100.0, 'clicks' => 10.0, 'conversions' => 20.0]);
        $this->creatives($this->meta, $traffic, 'image', 2, ['spend' => 40.0, 'clicks' => 400.0, 'conversions' => 0.0]);

        $answer = $this->ask();
        $byFamily = collect($answer['objectives'])->keyBy('family');

        $this->assertSame(['sales', 'traffic'], $byFamily->keys()->sort()->values()->all());
        $this->assertSame(['conversions', 'cpa'], [$byFamily['sales']['result_metric'], $byFamily['sales']['cost_metric']]);
        $this->assertSame(['clicks', 'cpc'], [$byFamily['traffic']['result_metric'], $byFamily['traffic']['cost_metric']]);
        $this->assertEquals(400.0, $byFamily['sales']['totals']['spend'], 'the traffic spend never enters the sales comparison');
        $this->assertEquals(60.0, $byFamily['sales']['totals']['results']);
        $this->assertEquals(800.0, $byFamily['traffic']['totals']['results'], 'clicks are the traffic result');
        $this->assertSame(['image'], array_column($byFamily['traffic']['formats'], 'format'));
        $this->assertTrue($answer['totals']['reconciles']);
        $this->assertEquals(480.0, $answer['totals']['spend']);
    }

    // ── the verdict: the canonical ranker, on cost per result, never ROAS ────────────────────────────

    public function test_the_winner_is_the_lowest_cost_per_result_and_never_decided_on_roas(): void
    {
        $c = $this->campaign('sales');
        // Image: CPA 10, ROAS 10. Video: CPA 5, ROAS 2. A ROAS verdict would crown image.
        $this->creatives($this->meta, $c, 'image', 2, ['spend' => 50.0, 'conversions' => 5.0, 'revenue' => 500.0]);
        $this->creatives($this->meta, $c, 'video', 2, ['spend' => 50.0, 'conversions' => 10.0, 'revenue' => 100.0]);

        $sales = $this->ask()['objectives'][0];

        $this->assertSame(['format' => 'video', 'metric' => 'cpa', 'value' => 5.0], $sales['winner']);
        $this->assertTrue($sales['lower_is_better']);
        $this->assertEqualsWithDelta(10.0, $this->format('sales', 'image')['roas'], 1e-9, 'ROAS is shown where revenue was reported');
        $this->assertEqualsWithDelta(0.5, $this->format('sales', 'image')['share_of_spend'], 1e-9);
        $this->assertEqualsWithDelta(10 / 30, $this->format('sales', 'image')['share_of_results'], 1e-9);
    }

    // ── small samples: shown, chipped, never crowned ───────────────────────────────────────────────────

    public function test_a_small_sample_is_marked_and_cannot_win(): void
    {
        $c = $this->campaign('sales');
        $this->creatives($this->meta, $c, 'image', 2, ['spend' => 100.0, 'conversions' => 10.0]);
        // One creative at a CPA of 1 — the cheapest, and an anecdote.
        $this->creatives($this->meta, $c, 'carousel', 1, ['spend' => 5.0, 'conversions' => 5.0]);
        // Two creatives, under 1% of the objective's spend.
        $this->creatives($this->meta, $c, 'collection', 2, ['spend' => 0.5, 'conversions' => 1.0]);

        $sales = $this->ask()['objectives'][0];
        $formats = collect($sales['formats'])->keyBy('format');

        $this->assertSame('fewer_creatives_than_minimum', $formats['carousel']['small_sample_reason']);
        $this->assertSame('below_material_share_of_spend', $formats['collection']['small_sample_reason']);
        $this->assertFalse($formats['image']['small_sample']);
        $this->assertNull($sales['winner'], 'one comparable format is not a comparison');
        $this->assertSame('fewer_than_two_comparable_formats', $sales['winner_refusal']);
        $this->assertSame(['minimum_creatives' => 2, 'material_share' => 0.01], $this->ask()['sample_rule']);
    }

    // ── money: a withheld amount is never a zero ─────────────────────────────────────────────────────

    public function test_withheld_spend_is_null_with_its_original_and_takes_no_share_or_verdict(): void
    {
        $c = $this->campaign('sales');
        $this->creatives($this->meta, $c, 'image', 2, ['spend' => 100.0, 'conversions' => 10.0]);
        $this->creatives($this->meta, $c, 'video', 2, ['spend' => null, 'spend_original' => 40.0, 'original_currency' => 'USD', 'conversions' => 20.0]);

        $answer = $this->ask();
        $video = $this->format('sales', 'video');

        $this->assertNull($video['spend']);
        $this->assertTrue($video['spend_withheld']);
        $this->assertEquals(80.0, $video['spend_original']);
        $this->assertSame('USD', $video['original_currency']);
        $this->assertNull($video['cost_per_result'], 'a cost over a withheld amount is not a cost');
        $this->assertNull($video['share_of_spend']);
        $this->assertEquals(40.0, $video['results'], 'results are not money');
        $this->assertNull($answer['objectives'][0]['totals']['spend'], 'a total with a withheld part is withheld');
        $this->assertNull($answer['coverage']['spend_known_share']);
        $this->assertNull($answer['objectives'][0]['winner']);
    }

    // ── attribution: a zero the campaign contradicts is not a result ────────────────────────────────

    public function test_results_never_attributed_at_creative_grain_are_not_results(): void
    {
        $sold = $this->campaign('sales');
        $this->creatives($this->meta, $sold, 'video', 2, ['spend' => 60.0, 'conversions' => 0.0]);
        $this->campaignMetric($sold, 'conversions', 50.0);
        $other = $this->campaign('sales');
        $this->creatives($this->meta, $other, 'image', 2, ['spend' => 40.0, 'conversions' => 4.0]);

        $video = $this->format('sales', 'video');

        $this->assertNull($video['results'], 'the campaign sold 50 while every creative read 0: not attributed here');
        $this->assertNull($video['cost_per_result']);
        $this->assertEquals(120.0, $video['spend'], 'the spend is still spend');
        $this->assertNull($this->ask()['objectives'][0]['winner']);
    }

    // ── isolation ─────────────────────────────────────────────────────────────────────────────────────

    public function test_another_project_and_a_deselected_account_never_enter(): void
    {
        $c = $this->campaign('sales');
        $this->creatives($this->meta, $c, 'image', 2, ['spend' => 10.0, 'conversions' => 1.0]);

        $stranger = $this->account('meta', 'Stranger');
        $this->bind($stranger, $this->other, true);
        $theirs = UnifiedCampaign::create(['tenant_id' => $this->tenant->getKey(), 'project_id' => $this->other->getKey(), 'name' => 'T-'.uniqid(), 'status' => 'active', 'objective' => 'sales']);
        $this->creatives($stranger, $theirs, 'video', 3, ['spend' => 99999.0, 'conversions' => 1.0], [], $this->other, 'LEAK-OTHER');

        $off = $this->account('tiktok', 'Deselected');
        $this->bind($off, $this->project, false);
        $this->creatives($off, $c, 'video', 2, ['spend' => 77777.0, 'conversions' => 1.0], [], null, 'LEAK-DESELECTED');

        $answer = $this->ask();
        $flat = (string) json_encode($answer, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('LEAK-', $flat);
        $this->assertStringNotContainsString('99999', $flat);
        $this->assertStringNotContainsString('77777', $flat);
        $this->assertSame(['image'], array_column($answer['objectives'][0]['formats'], 'format'));
        $this->assertEquals(20.0, $answer['totals']['spend']);
    }

    // ── the window and the previous one ─────────────────────────────────────────────────────────────

    public function test_out_of_window_rows_are_excluded_and_the_previous_period_is_its_own_reading(): void
    {
        $c = $this->campaign('sales');
        $id = $this->creative($this->meta, $c, 'image', ['spend' => 30.0, 'conversions' => 3.0]);
        $this->creative($this->meta, $c, 'image', ['spend' => 10.0, 'conversions' => 1.0]);
        $this->metricRow($id, $c, '2026-08-20', ['spend' => 500.0, 'conversions' => 5.0]);
        $this->metricRow($id, $c, '2026-10-01', ['spend' => 900.0, 'conversions' => 9.0]);

        $image = $this->format('sales', 'image');

        $this->assertEquals(40.0, $image['spend']);
        $this->assertEquals(4.0, $image['results']);
        $this->assertEquals(500.0, $image['previous']['spend']);
        $this->assertEquals(100.0, $image['previous']['cost_per_result']);
        $this->assertSame(['from' => '2026-08-02', 'to' => '2026-08-31'], $this->ask()['previous_period']);
    }

    // ── the Analytics endpoint ───────────────────────────────────────────────────────────────────────

    public function test_the_endpoint_returns_the_service_answer_and_requires_a_session(): void
    {
        $c = $this->campaign('sales');
        $this->creatives($this->meta, $c, 'image', 2, ['spend' => 100.0, 'conversions' => 10.0]);
        $this->creatives($this->meta, $c, 'video', 2, ['spend' => 100.0, 'conversions' => 25.0]);

        $this->getJson("/api/v1/projects/{$this->project->getKey()}/creatives/format-comparison")->assertUnauthorized();

        $served = $this->actingAs($this->operator(), 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->getKey()}/creatives/format-comparison?from=2026-09-01&to=2026-09-30")
            ->assertOk()->json('data');

        $this->assertEquals($this->ask(), $served);
        $this->assertSame('video', $served['objectives'][0]['winner']['format']);
    }

    // ── the report part ──────────────────────────────────────────────────────────────────────────────

    public function test_the_report_part_is_registered_and_shown_unless_hidden(): void
    {
        $section = app(ReportSectionRegistry::class)->get(ContentFormatComparison::KEY);

        $this->assertSame([ContentFormatComparison::KEY], $section->payloadKeys);
        $this->assertTrue($section->defaultFor('client'));
        $this->assertTrue($section->defaultFor('internal'));
        $this->assertSame('مقارنة أنواع المحتوى', $section->titleAr);
    }

    public function test_a_client_link_reads_results_and_shares_without_spend_cost_or_campaign_names(): void
    {
        $this->reportFixture();
        $live = $this->read($this->link($this->report('client')));
        $block = $live[ContentFormatComparison::KEY];

        $this->assertContains(ContentFormatComparison::KEY, $live['report_sections']);
        $this->assertSame([], $this->moneyNumbers($block), 'a client reads no spend and no cost');
        $this->assertTrue($block['spend_hidden']);
        $sales = $block['objectives'][0];
        $this->assertSame('video', $sales['winner']['format'], 'the verdict survives — without the figure it was decided on');
        $this->assertNull($sales['winner']['value']);
        $this->assertEquals(35.0, $sales['totals']['results']);
        $this->assertStringNotContainsString('CAMPAIGN-NAME', (string) json_encode($block, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('Creative 0', (string) json_encode($block, JSON_UNESCAPED_UNICODE), 'content names are a client’s to read');

        // An internal report keeps the money.
        $internal = $this->read($this->link($this->report('internal')))[ContentFormatComparison::KEY];
        $this->assertEquals(200.0, $internal['totals']['spend']);
        $this->assertEquals(4.0, $internal['objectives'][0]['winner']['value']);
    }

    public function test_hiding_the_part_takes_it_out_of_the_json_the_csv_and_the_pdf_data(): void
    {
        $this->reportFixture();
        $report = $this->report('internal');
        $data = $this->snapshot($report);
        $this->assertIsArray($data[ContentFormatComparison::KEY]);
        Report::withoutGlobalScopes()->whereKey($report->id)->update(['data' => json_encode($data)]);

        // Shown: every surface carries it.
        $shown = $this->read($this->link($report));
        $this->assertIsArray($shown[ContentFormatComparison::KEY]);
        $this->assertIsArray($this->printed($report)[ContentFormatComparison::KEY] ?? null);
        $this->assertStringContainsString('Content types / أنواع المحتوى', $this->csv($report));

        // Hidden by the operator: absent from the link's JSON, the file and the print route the PDF is rendered from.
        Report::withoutGlobalScopes()->whereKey($report->id)->update(['section_settings' => json_encode(['sections' => [ContentFormatComparison::KEY => false]])]);
        $report = $report->fresh();
        $live = $this->read($this->link($report));
        $this->assertNotContains(ContentFormatComparison::KEY, $live['report_sections']);
        $this->assertNull($live[ContentFormatComparison::KEY] ?? null);
        $this->assertArrayNotHasKey(ContentFormatComparison::KEY, $this->printed($report));
        $this->assertStringNotContainsString('Content types', $this->csv($report));
        // Hiding it took nothing else with it.
        $this->assertSame(array_values(array_diff($shown['report_sections'], [ContentFormatComparison::KEY])), $live['report_sections']);

        // The stored snapshot itself was never rewritten.
        $this->assertIsArray(Report::withoutGlobalScopes()->find($report->id)->data[ContentFormatComparison::KEY]);
        // A snapshot generated while it is hidden does not freeze it.
        $this->assertNull($this->snapshot($report)[ContentFormatComparison::KEY]);
    }

    public function test_a_saved_snapshot_freezes_the_figures(): void
    {
        $this->reportFixture();
        $report = $this->report('client');
        $data = $this->snapshot($report);
        Report::withoutGlobalScopes()->whereKey($report->id)->update(['data' => json_encode($data)]);
        $this->assertSame([], $this->moneyNumbers($data[ContentFormatComparison::KEY]), 'a client snapshot never stores the spend');

        DB::table('creative_daily_metrics')->update(['conversions' => 999]);

        $stored = Report::withoutGlobalScopes()->find($report->id)->data[ContentFormatComparison::KEY];
        $this->assertEquals(35.0, $stored['objectives'][0]['totals']['results']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function ask(): array
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        return app(ContentFormatComparison::class)->forScope((string) $this->project->getKey(), Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));
    }

    /** @return array<string, mixed> */
    private function format(string $family, string $format): array
    {
        $objective = collect($this->ask()['objectives'])->firstWhere('family', $family);

        return collect($objective['formats'])->firstWhere('format', $format);
    }

    /** @return list<string> every money path still carrying a number */
    private function moneyNumbers(array $data, string $path = ''): array
    {
        $found = [];
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $found = [...$found, ...$this->moneyNumbers($v, $path.'/'.$k)];
            } elseif (is_numeric($v) && in_array($k, ['spend', 'spend_original', 'cost_per_result', 'cpm', 'cpc', 'roas', 'share_of_spend', 'spend_known_share', 'value'], true)) {
                $found[] = $path.'/'.$k;
            }
        }

        return $found;
    }

    private function reportFixture(): void
    {
        $c = $this->campaign('sales', 'CAMPAIGN-NAME');
        $this->creatives($this->meta, $c, 'image', 2, ['spend' => 50.0, 'conversions' => 5.0]);
        $this->creatives($this->meta, $c, 'video', 2, ['spend' => 50.0, 'conversions' => 12.5]);
    }

    private function report(string $audience): Report
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        return Report::create([
            'project_id' => $this->project->getKey(), 'name' => 'R', 'type' => 'executive', 'status' => 'completed', 'audience' => $audience,
            'currency' => 'SAR', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'data' => [],
        ]);
    }

    private function link(Report $report): string
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
        [, $raw] = app(ShareService::class)->create($report->fresh(), ['scope' => [
            'project_id' => (string) $this->project->getKey(), 'campaign_ids' => [], 'account_ids' => [(string) $this->meta->getKey(), (string) $this->snap->getKey()],
            'providers' => [], 'dynamic_campaigns' => true, 'earliest' => '2026-09-01', 'latest' => '2026-09-30',
        ]], null);
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        return $raw;
    }

    /** @return array<string, mixed> */
    private function read(string $raw): array
    {
        return $this->getJson("/api/v1/reports/shared/{$raw}/live?from=2026-09-01&to=2026-09-30")->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    private function snapshot(Report $report): array
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());
        $data = app(ReportGenerator::class)->generate($report->fresh());
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        return $data;
    }

    /** @return array<string, mixed> */
    private function printed(Report $report): array
    {
        $token = Str::random(48);
        Cache::put('report-print:'.hash('sha256', $token), [
            'report_id' => (string) $report->getKey(), 'type' => 'presentation', 'theme' => 'light', 'audience' => (string) $report->audience, 'watermark' => false,
        ], 300);

        return $this->getJson("/api/v1/reports/print/{$token}")->assertOk()->json('data.data');
    }

    private function csv(Report $report): string
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());
        $csv = app(ReportExporter::class)->render($report->fresh(), 'csv');
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        return $csv;
    }

    private function operator(): User
    {
        $this->seed(PermissionSeeder::class);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'Op', 'slug' => 'op-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create(['name' => 'Op', 'email' => 'cfc-'.uniqid().'@a.test', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return $user;
    }

    private function account(string $provider, string $name): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->getKey(), provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: $provider.uniqid(),
        );

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'provider_connection_id' => $connection->getKey(),
            'provider' => $provider, 'account_type' => 'ad_account', 'external_id' => 'a-'.uniqid(),
            'name' => $name, 'currency' => 'SAR', 'status' => 'active',
        ]);
    }

    private function bind(ExternalAccount $account, Project $project, bool $active): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $project->client_workspace_id,
            'project_id' => $project->getKey(), 'external_account_id' => $account->getKey(),
            'provider' => $account->provider, 'purpose' => 'advertising', 'is_active' => $active,
        ]);
    }

    private function campaign(string $objective, string $name = 'C'): UnifiedCampaign
    {
        return UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => $name.'-'.uniqid(), 'status' => 'active', 'objective' => $objective,
        ]);
    }

    /**
     * @param  array<string, float|string|null>  $metrics
     * @param  array<string, mixed>  $extra
     */
    private function creatives(ExternalAccount $account, UnifiedCampaign $campaign, ?string $format, int $count, array $metrics, array $extra = [], ?Project $project = null, string $prefix = 'Creative '): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->creative($account, $campaign, $format, $metrics, $extra, $project, $prefix.$i);
        }
    }

    /**
     * @param  array<string, float|string|null>  $metrics
     * @param  array<string, mixed>  $extra
     */
    private function creative(ExternalAccount $account, UnifiedCampaign $campaign, ?string $format, array $metrics, array $extra = [], ?Project $project = null, string $name = 'Creative'): string
    {
        $project ??= $this->project;
        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $project->getKey(),
            'external_account_id' => $account->getKey(), 'unified_campaign_id' => $campaign->getKey(),
            'provider' => $account->provider, 'external_id' => 'ec-'.uniqid(), 'name' => $campaign->name, 'status' => 'active',
        ]);
        $creative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $project->getKey(),
            'campaign_id' => $campaign->getKey(), 'external_campaign_id' => $external->getKey(),
            'external_account_id' => $account->getKey(), 'provider' => $account->provider,
            'external_id' => 'cr-'.uniqid(), 'external_creative_id' => 'xc-'.uniqid(), 'format' => $format, 'name' => $name,
        ] + $extra);
        $this->metricRow((string) $creative->getKey(), $campaign, '2026-09-15', $metrics, $project);

        return (string) $creative->getKey();
    }

    /** @param array<string, float|string|null> $metrics */
    private function metricRow(string $creativeId, UnifiedCampaign $campaign, string $date, array $metrics, ?Project $project = null): void
    {
        DB::table('creative_daily_metrics')->insert(array_merge([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->getKey(), 'project_id' => ($project ?? $this->project)->getKey(),
            'creative_id' => $creativeId, 'campaign_id' => $campaign->getKey(), 'metric_date' => $date,
            'created_at' => now(), 'updated_at' => now(),
        ], $metrics));
    }

    private function campaignMetric(UnifiedCampaign $campaign, string $key, float $value): void
    {
        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(), 'external_account_id' => $this->meta->getKey(),
            'unified_campaign_id' => $campaign->getKey(), 'provider' => 'meta', 'external_id' => 'ec-'.uniqid(), 'name' => $campaign->name, 'status' => 'active',
        ]);
        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_account_id' => $this->meta->getKey(), 'external_campaign_id' => $external->getKey(), 'unified_campaign_id' => $campaign->getKey(), 'provider' => 'meta',
            'metric_key' => $key, 'metric_date' => '2026-09-15', 'value' => $value, 'project_currency' => 'SAR',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
