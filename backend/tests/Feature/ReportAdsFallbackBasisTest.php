<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Services\ReportAds;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CHATGPT-ADS-CONTENT-RANKABLE-001 — a platform that reports spend and clicks but none of the objective's
 * result still shows its ads.
 *
 * The shared link's ChatGPT Ads view showed spend and clicks over «no content with figures»: the campaign's
 * objective is sales, the platform reports no orders or revenue, so the ranker found nothing to rank on and
 * the payload's `ads` was empty while eight creatives with real figures sat in the roster. The order now
 * falls back to the volume the platform DID report, and says so.
 */
final class ReportAdsFallbackBasisTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'Project 1', 'status' => 'active',
        ]);
    }

    /** Eight chat-card creatives on a platform that reports spend, clicks and impressions and nothing else. */
    private function chatCards(string $provider = 'openai_ads', string $objective = 'sales'): void
    {
        $campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'provider' => $provider,
            'external_id' => 'camp-1', 'name' => 'Campaign 1', 'status' => 'active', 'objective' => $objective,
        ]);
        foreach ([[36.89, 110], [30.0, 80], [25.5, 60], [20.0, 40], [12.0, 25], [8.0, 15], [5.0, 8], [3.87, 5]] as $i => [$spend, $clicks]) {
            $creative = ExternalCreative::create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'campaign_id' => $campaign->id,
                'provider' => $provider, 'external_creative_id' => 'card-'.$i, 'name' => 'Card '.($i + 1), 'format' => 'chat_card',
                'asset_url' => 'https://cdn.test/card-'.$i.'.jpg',
            ]);
            DB::table('creative_daily_metrics')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                'creative_id' => $creative->id, 'metric_date' => '2026-07-15',
                'spend' => $spend, 'impressions' => $clicks * 40, 'clicks' => $clicks,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function section(string $objective, string $provider = 'openai_ads'): array
    {
        return app(ReportAds::class)->for($objective, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'), [
            'project_ids' => [(string) $this->project->id], 'providers' => [$provider],
        ]);
    }

    public function test_a_sales_platform_with_no_orders_ranks_its_ads_on_clicks_and_says_so(): void
    {
        $this->chatCards();

        $section = $this->section('sales');

        $this->assertNull($section['reason'], 'the section is not absent: the ads have figures');
        $this->assertCount(5, $section['ads']);
        $this->assertSame('Card 1', $section['ads'][0]['name']);
        $this->assertSame('clicks', $section['basis']['metric']);
        $this->assertTrue($section['basis']['fallback']);
        $this->assertStringContainsString('طلبات', (string) $section['basis']['note_ar']);
        $this->assertStringContainsString('النقرات', (string) $section['basis']['note_ar']);
        $this->assertStringContainsString('does not report orders', (string) $section['basis']['note_en']);
        $this->assertSame('clicks', $section['ads'][0]['reason_basis']);
        $this->assertNotEmpty($section['ads'][0]['preview'], 'the creative preview travels with the ad');
        $this->assertNotContains('roas', array_filter([$section['basis']['metric']]), 'never invents a return');
        $this->assertTrue($section['groups'][0]['ranked']);
        $this->assertSame('clicks', $section['groups'][0]['metric']);
        $this->assertTrue($section['groups'][0]['fallback']);
        $this->assertCount(8, $section['roster']);
    }

    public function test_a_mixed_objective_scope_headlines_the_fallback_group_with_its_note(): void
    {
        $this->chatCards();

        $section = $this->section('custom');

        $this->assertNull($section['reason']);
        $this->assertNotSame([], $section['ads']);
        $this->assertSame('clicks', $section['basis']['metric']);
        $this->assertTrue($section['basis']['fallback']);
    }

    public function test_an_objective_whose_result_is_reported_is_not_marked_as_a_fallback(): void
    {
        $this->chatCards('meta', 'traffic');

        $section = $this->section('traffic', 'meta');

        $this->assertFalse($section['basis']['fallback'] ?? false);
        $this->assertNull($section['basis']['note_ar'] ?? null);
    }

    /** @return array<string, array{0: string}> */
    public static function resultlessObjectives(): array
    {
        return ['leads' => ['leads'], 'app' => ['app'], 'engagement' => ['engagement'], 'video' => ['video'], 'awareness' => ['awareness']];
    }

    /** The same defect must not hit any other platform that lacks its objective's result. */
    #[DataProvider('resultlessObjectives')]
    public function test_no_objective_leaves_a_platform_with_spend_and_clicks_without_content(string $objective): void
    {
        $this->chatCards('snapchat', $objective);

        $section = $this->section($objective, 'snapchat');

        $this->assertNotSame([], $section['ads'], "{$objective} must still list ads that have spend and clicks");
        $this->assertNull($section['reason']);
    }
}
