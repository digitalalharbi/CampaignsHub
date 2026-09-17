<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REPORT-SECTION-STREAMS-001 — Direct/Blended leaves every client surface; advanced segmentation
 * carries operator-named business streams instead, and only when an operator turned it on.
 */
final class BusinessStreamsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

    private User $operator;

    private string $metaAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Streams', 'slug' => 'streams-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());

        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'Op', 'slug' => 'op-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create(['name' => 'Op', 'email' => 'op@streams.local', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'Internal campaign name', 'status' => 'active', 'objective' => 'sales',
        ]);

        $this->metaAccount = (string) Str::uuid();
        $this->metric('meta', $this->metaAccount, ['spend' => 300.0, 'conversions' => 10.0, 'impressions' => 5000.0, 'clicks' => 100.0]);
        $this->metric('snapchat', (string) Str::uuid(), ['spend' => 100.0, 'conversions' => 2.0, 'impressions' => 9000.0, 'clicks' => 40.0]);
    }

    /** @param array<string, float> $values */
    private function metric(string $provider, string $account, array $values): void
    {
        foreach ($values as $key => $value) {
            DailyMetric::create([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
                'external_account_id' => $account, 'external_campaign_id' => (string) Str::uuid(),
                'unified_campaign_id' => $this->campaign->getKey(), 'provider' => $provider, 'metric_key' => $key,
                'metric_date' => now()->subDays(2)->toDateString(), 'value' => $value,
            ]);
        }
    }

    private function report(array $settings = [], string $audience = 'client'): Report
    {
        return Report::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'R', 'type' => 'monthly', 'form' => 'detailed', 'audience' => $audience, 'status' => 'completed', 'currency' => 'SAR',
            'period_start' => now()->subDays(30)->toDateString(), 'period_end' => now()->toDateString(),
            'section_settings' => $settings ?: null,
        ]);
    }

    private function live(Report $report, array $providers = ['meta', 'snapchat']): array
    {
        [, $raw] = app(ShareService::class)->create($report, [
            'mode' => 'live',
            'scope' => [
                'project_id' => (string) $this->project->getKey(),
                'campaign_ids' => [(string) $this->campaign->getKey()],
                'providers' => $providers,
                'earliest' => now()->subDays(30)->toDateString(),
                'latest' => now()->toDateString(),
            ],
        ], null);

        return $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data');
    }

    private const STREAMS = [
        ['label' => 'المبيعات عبر الإنترنت', 'providers' => ['meta']],
        ['label' => 'التوعية في الفروع', 'providers' => ['snapchat']],
    ];

    public function test_a_client_link_carries_neither_the_split_nor_streams_by_default(): void
    {
        $payload = $this->live($this->report(['streams' => self::STREAMS]));

        $this->assertArrayNotHasKey('objective_performance', $payload);
        $this->assertArrayNotHasKey('business_streams', $payload);
        $this->assertNotContains('advanced_segmentation', $payload['report_sections']);
    }

    public function test_with_advanced_segmentation_on_the_client_gets_streams_and_still_no_direct_or_blended(): void
    {
        $payload = $this->live($this->report(['sections' => ['advanced_segmentation' => true], 'streams' => self::STREAMS]));

        $this->assertContains('advanced_segmentation', $payload['report_sections']);
        $this->assertArrayNotHasKey('objective_performance', $payload, 'Direct/Blended is buying methodology, never a client concept');

        $streams = collect($payload['business_streams'])->keyBy('label');
        $this->assertEqualsWithDelta(300.0, (float) $streams['المبيعات عبر الإنترنت']['figures']['spend'], 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $streams['التوعية في الفروع']['figures']['spend'], 0.001);
        // Ratios from the stream's own sums, never averaged.
        $this->assertEqualsWithDelta(30.0, (float) $streams['المبيعات عبر الإنترنت']['figures']['cpa'], 0.001);
        $this->assertEqualsWithDelta(0.75, (float) $streams['المبيعات عبر الإنترنت']['share_of_spend'], 0.0001);

        // The main report stays the truthful overall figures.
        $this->assertEqualsWithDelta(400.0, (float) $payload['totals']['spend'], 0.001);

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '';
        $this->assertStringNotContainsString('Internal campaign name', $json);
        $this->assertStringNotContainsStringIgnoringCase('blended', $json);
    }

    public function test_a_stream_mapped_outside_the_links_scope_matches_nothing_rather_than_widening_it(): void
    {
        $payload = $this->live($this->report(['sections' => ['advanced_segmentation' => true], 'streams' => self::STREAMS]), providers: ['meta']);

        $streams = collect($payload['business_streams'])->keyBy('label');
        $this->assertEqualsWithDelta(0.0, (float) $streams['التوعية في الفروع']['figures']['spend'], 0.001);
    }

    public function test_the_snapshot_and_its_print_route_follow_the_same_rule_and_internal_keeps_the_split(): void
    {
        $client = $this->report(['sections' => ['advanced_segmentation' => true], 'streams' => self::STREAMS]);
        (new GenerateReportJob((string) $client->getKey()))->handle(app(ReportGenerator::class));
        $this->assertCount(2, $client->refresh()->data['business_streams']);

        $internal = $this->report(['streams' => self::STREAMS], audience: 'internal');
        (new GenerateReportJob((string) $internal->getKey()))->handle(app(ReportGenerator::class));

        foreach (['client' => $client, 'internal' => $internal] as $audience => $report) {
            $token = Str::random(48);
            Cache::put('report-print:'.hash('sha256', $token), [
                'report_id' => (string) $report->getKey(), 'type' => 'presentation', 'theme' => 'light', 'audience' => $audience,
            ], 300);
            $body = $this->getJson("/api/v1/reports/print/{$token}")->assertOk()->json('data.data');

            $this->assertCount(2, $body['business_streams'], $audience);
            if ($audience === 'client') {
                $this->assertArrayNotHasKey('objective_performance', $body);
                $this->assertNotContains('objective_performance', array_column($body['slides'] ?? [], 'type'));
            } else {
                $this->assertArrayHasKey('objective_performance', $body, 'an internal report keeps its methodology');
            }
        }
    }

    public function test_an_operator_saves_streams_foreign_accounts_are_dropped_and_a_snapshot_regenerates(): void
    {
        Queue::fake();
        $report = $this->report();
        $report->update(['data' => ['kpis' => ['spend' => 1]]]);
        $url = "/api/v1/projects/{$this->project->getKey()}/reports/{$report->getKey()}/sections";

        $data = $this->actingAs($this->operator, 'sanctum')->putJson($url, ['streams' => [
            ['label' => 'الاستحواذ', 'account_ids' => [$this->metaAccount, (string) Str::uuid()]],
            ['label' => 'لا شيء', 'account_ids' => [(string) Str::uuid()]],
        ]])->assertOk()->json('data');

        $this->assertSame([['key' => 's1', 'label' => 'الاستحواذ', 'providers' => [], 'account_ids' => [$this->metaAccount]]], $data['streams']);
        Queue::assertPushed(GenerateReportJob::class);

        $this->actingAs($this->operator, 'sanctum')
            ->putJson($url, ['streams' => [['providers' => ['meta']]]])
            ->assertUnprocessable();
    }

    /**
     * Coordinator decision — a platform or an ad account belongs to at most ONE stream.
     *
     * Overlapping streams count the same money twice, so they cannot be read side by side. Refused
     * server-side with the conflict named, rather than stored and explained away on the page.
     */
    public function test_a_platform_in_two_streams_is_refused_and_named(): void
    {
        $report = $this->report();
        $url = "/api/v1/projects/{$this->project->getKey()}/reports/{$report->getKey()}/sections";

        $res = $this->actingAs($this->operator, 'sanctum')->putJson($url, ['streams' => [
            ['label' => 'الاستحواذ', 'providers' => ['meta', 'snapchat']],
            ['label' => 'إعادة الاستهداف', 'providers' => ['meta']],
        ]])->assertUnprocessable();
        $this->assertStringContainsString('platform meta', json_encode($res->json(), JSON_UNESCAPED_UNICODE));

        $this->assertNull($report->fresh()->section_settings);
    }

    public function test_an_account_in_two_streams_or_under_a_platform_another_stream_holds_is_refused(): void
    {
        $report = $this->report();
        $url = "/api/v1/projects/{$this->project->getKey()}/reports/{$report->getKey()}/sections";

        $res = $this->actingAs($this->operator, 'sanctum')->putJson($url, ['streams' => [
            ['label' => 'أ', 'account_ids' => [$this->metaAccount]],
            ['label' => 'ب', 'account_ids' => [$this->metaAccount]],
        ]])->assertUnprocessable();
        $this->assertStringContainsString($this->metaAccount, json_encode($res->json(), JSON_UNESCAPED_UNICODE));

        // The account is Meta's, and Meta as a whole already sits in the first stream.
        $res = $this->actingAs($this->operator, 'sanctum')->putJson($url, ['streams' => [
            ['label' => 'أ', 'providers' => ['meta']],
            ['label' => 'ب', 'account_ids' => [$this->metaAccount]],
        ]])->assertUnprocessable();
        $this->assertStringContainsString("ad account {$this->metaAccount} (meta)", json_encode($res->json(), JSON_UNESCAPED_UNICODE));

        $this->assertNull($report->fresh()->section_settings);
    }

    /** The sum of the streams is the total only when every in-scope account is mapped. */
    public function test_streams_say_whether_they_cover_the_whole_report(): void
    {
        $partial = $this->live($this->report(['sections' => ['advanced_segmentation' => true], 'streams' => [
            ['label' => 'المبيعات عبر الإنترنت', 'providers' => ['meta']],
        ]]));
        $this->assertFalse($partial['business_streams_cover_total']);

        $whole = $this->live($this->report(['sections' => ['advanced_segmentation' => true], 'streams' => self::STREAMS]));
        $this->assertTrue($whole['business_streams_cover_total']);
        $this->assertEqualsWithDelta(
            (float) $whole['totals']['spend'],
            collect($whole['business_streams'])->sum(fn (array $s): float => (float) $s['figures']['spend']),
            0.001,
        );
    }
}
