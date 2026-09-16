<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Reports\Support\ContentKey;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The platform → content drilldown of a live client link.
 *
 * What is asserted is the contract a client relies on, and each case compares SURFACES rather than a
 * literal: the drilldown's total against the card it was opened from, and the trend's points against
 * that total. A test pinned to «1000» passes while both drift together.
 */
final class LiveContentDrilldownTest extends TestCase
{
    use RefreshDatabase;

    private Report $report;

    private Project $project;

    /** @var list<string> */
    private array $campaigns = [];

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'A', 'slug' => 'drill-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId($this->project->id);

        foreach (['meta' => [13.5, 7.25, 101.75], 'tiktok' => [40.0, 2.5]] as $provider => $daily) {
            $campaign = UnifiedCampaign::create(['project_id' => $this->project->id, 'name' => "C {$provider}", 'status' => 'active', 'objective' => 'sales']);
            $this->campaigns[] = $campaign->id;
            $creative = ExternalCreative::create([
                'tenant_id' => $tenant->id, 'project_id' => $this->project->id, 'campaign_id' => $campaign->id,
                'provider' => $provider, 'external_creative_id' => 'cr-'.Str::random(8),
                'name' => "{$provider} creative", 'format' => 'image', 'status' => 'active',
            ]);
            foreach ($daily as $i => $spend) {
                $date = sprintf('2026-07-%02d', 10 + $i);
                DB::table('creative_daily_metrics')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'project_id' => $this->project->id,
                    'creative_id' => $creative->id, 'metric_date' => $date, 'spend' => $spend,
                    'impressions' => 1000 + $i * 7, 'clicks' => 30 + $i, 'conversions' => 1 + $i, 'revenue' => $spend * 3,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DailyMetric::create([
                    'id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'external_account_id' => (string) Str::uuid(),
                    'external_campaign_id' => (string) Str::uuid(), 'unified_campaign_id' => $campaign->id,
                    'provider' => $provider, 'metric_key' => 'spend', 'metric_date' => $date, 'value' => $spend,
                ]);
            }
        }

        $this->report = Report::create([
            'project_id' => $this->project->id, 'name' => 'R', 'type' => 'executive', 'status' => 'completed',
            'currency' => 'SAR', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'data' => [],
        ]);

        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
    }

    /** @param array<string, mixed> $overrides @return array{0: ReportShare, 1: string} */
    private function share(array $overrides = []): array
    {
        return app(ShareService::class)->create($this->report, [
            'scope' => [
                'project_id' => $this->project->id, 'campaign_ids' => $this->campaigns,
                'providers' => ['meta', 'tiktok'], 'earliest' => '2026-07-01', 'latest' => '2026-07-31',
            ],
        ] + $overrides, null);
    }

    /** @return array<string, mixed> */
    private function live(string $raw, string $query = ''): array
    {
        return $this->getJson("/api/v1/reports/shared/{$raw}/live?from=2026-07-01&to=2026-07-31{$query}")->assertOk()->json('data');
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function rosterRow(array $data, string $name): array
    {
        return collect($data['ads_roster'])->firstWhere('name', $name);
    }

    public function test_every_content_row_carries_a_key_and_no_internal_id(): void
    {
        [, $raw] = $this->share();
        $data = $this->live($raw);

        $rows = collect($data['ads_roster'])->merge($data['ads'] ?? [])->merge($data['ads_weakest'] ?? []);
        $this->assertGreaterThan(0, $rows->count(), 'No content in the payload, so this asserts nothing.');
        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/', (string) ($row['content_key'] ?? ''));
            $this->assertArrayNotHasKey('id', $row);
            $this->assertArrayNotHasKey('campaign_id', $row);
        }
    }

    public function test_the_drilldown_reconciles_with_the_card_it_was_opened_from(): void
    {
        [, $raw] = $this->share();
        $card = $this->rosterRow($this->live($raw), 'meta creative');

        $res = $this->getJson("/api/v1/reports/shared/{$raw}/live/content/{$card['content_key']}?from=2026-07-01&to=2026-07-31")->assertOk()->json('data');

        $this->assertSame('meta creative', $res['content']['name']);
        $this->assertEqualsWithDelta($card['metrics']['spend'], $res['content']['metrics']['spend'], 0.0001, 'The drilldown disagrees with the card about one creative.');

        $reported = collect($res['trend'])->where('reported', true);
        $this->assertCount(3, $reported, 'Three delivering days were seeded.');
        $this->assertEqualsWithDelta($res['content']['metrics']['spend'], $reported->sum('spend'), 0.0001, 'The trend does not add back to the total it explains.');
        $this->assertEqualsWithDelta($res['content']['metrics']['clicks'], $reported->sum('clicks'), 0.0001);

        $silent = collect($res['trend'])->where('reported', false)->first();
        $this->assertNotNull($silent);
        $this->assertArrayNotHasKey('spend', $silent, 'A day with no delivery must carry no figures, not zeros.');
    }

    public function test_a_platform_narrowed_payload_reconciles_with_the_whole_link(): void
    {
        [, $raw] = $this->share();
        $whole = collect($this->live($raw)['platforms'])->keyBy('provider');
        $meta = $this->live($raw, '&providers[]=meta');

        $this->assertEqualsWithDelta($whole['meta']['spend'], $meta['totals']['spend'], 0.0001, 'A platform view disagrees with the comparison row for the same platform.');
        $this->assertSame(['meta'], collect($meta['ads_roster'])->pluck('provider')->unique()->values()->all());
    }

    public function test_a_key_from_another_link_opens_nothing(): void
    {
        [, $first] = $this->share();
        [, $second] = $this->share();
        $key = $this->rosterRow($this->live($first), 'meta creative')['content_key'];

        $this->getJson("/api/v1/reports/shared/{$second}/live/content/{$key}")->assertNotFound();
        $this->getJson("/api/v1/reports/shared/{$first}/live/content/".str_repeat('a', 24))->assertNotFound();
    }

    public function test_a_key_outside_the_platform_ceiling_opens_nothing(): void
    {
        [, $wide] = $this->share();
        $tiktokKey = $this->rosterRow($this->live($wide), 'tiktok creative')['content_key'];

        [$narrow, $narrowRaw] = $this->share();
        $narrow->scope = ['providers' => ['meta']] + $narrow->scope;
        $narrow->save();
        // The same creative's key on the narrow link, computed the way the payload would have.
        $id = ExternalCreative::withoutGlobalScopes()->where('name', 'tiktok creative')->value('id');
        $key = ContentKey::for($narrow, (string) $id);

        $this->assertNotSame($tiktokKey, $key, 'A key must be bound to its share.');
        $this->getJson("/api/v1/reports/shared/{$narrowRaw}/live/content/{$key}")->assertNotFound();
    }

    public function test_hidden_spend_is_absent_from_the_drilldown_too(): void
    {
        [$share, $raw] = $this->share();
        $share->hide_spend = true;
        $share->save();
        $key = $this->rosterRow($this->live($raw), 'meta creative')['content_key'];

        $res = $this->getJson("/api/v1/reports/shared/{$raw}/live/content/{$key}?from=2026-07-01&to=2026-07-31")->assertOk()->json('data');

        $this->assertNull($res['content']['metrics']['spend'] ?? null);
        foreach ($res['trend'] as $point) {
            $this->assertNull($point['spend'] ?? null, 'A link that hides spend published it one click down.');
            $this->assertNull($point['cpa'] ?? null);
        }
    }

    public function test_a_link_that_does_not_show_content_refuses_the_drilldown(): void
    {
        [$share, $raw] = $this->share();
        $key = $this->rosterRow($this->live($raw), 'meta creative')['content_key'];
        $share->settings = ['sections' => ['creatives' => false]];
        $share->save();

        $this->getJson("/api/v1/reports/shared/{$raw}/live/content/{$key}")->assertNotFound();
    }
}
