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
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CLIENT-REPORT-ENTITY-BOUNDARY-001 — the LIVE report's content stops at the account ceiling too.
 *
 * `SharedCreativeView` was bound to the share's ad accounts (see `LiveReportAccountCeilingTest`), and
 * the live payload's own content lists — the ranked ads, the per-platform groups and the roster — are
 * built by `ReportAds` from `LiveReportService::adsFor()`, which passed project, providers and
 * campaigns and NOT the account axis. One link, two answers to «which content may this client see».
 *
 * The link names BOTH campaigns, so the account is the only axis that can exclude the second one.
 */
final class LiveReportContentAccountCeilingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_live_content_lists_stop_at_the_account_ceiling(): void
    {
        $tenant = Tenant::create(['name' => 'A', 'slug' => 'content-ceiling-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId($project->id);

        $granted = (string) Str::uuid();
        $other = (string) Str::uuid();

        $campaigns = [];
        foreach ([['Inside', $granted, 'meta'], ['Outside', $other, 'tiktok']] as [$label, $account, $provider]) {
            $campaign = UnifiedCampaign::create(['project_id' => $project->id, 'name' => $label, 'status' => 'active', 'objective' => 'sales']);
            $campaigns[] = $campaign->id;
            foreach (['spend' => 100, 'impressions' => 5000, 'clicks' => 60, 'conversions' => 4] as $key => $value) {
                DailyMetric::create([
                    'id' => (string) Str::uuid(), 'project_id' => $project->id, 'external_account_id' => $account,
                    'external_campaign_id' => (string) Str::uuid(), 'unified_campaign_id' => $campaign->id,
                    'provider' => $provider, 'metric_key' => $key, 'metric_date' => '2026-07-10', 'value' => $value,
                ]);
            }
            ExternalCreative::create([
                'tenant_id' => $tenant->id, 'project_id' => $project->id, 'campaign_id' => $campaign->id,
                'provider' => $provider, 'external_creative_id' => 'cr-'.Str::random(8),
                'name' => "{$label} creative", 'format' => 'image', 'status' => 'active',
                'last_active_at' => Carbon::parse('2026-07-10'),
            ]);
        }

        $report = Report::create([
            'project_id' => $project->id, 'name' => 'R', 'type' => 'executive', 'status' => 'completed',
            'currency' => 'SAR', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'data' => [],
        ]);
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        [, $raw] = app(ShareService::class)->create($report, [
            'scope' => [
                'project_id' => $project->id, 'campaign_ids' => $campaigns, 'account_ids' => [$granted],
                'providers' => ['meta', 'tiktok'], 'earliest' => '2026-07-01', 'latest' => '2026-07-31',
            ],
        ], null);

        $data = $this->getJson("/api/v1/reports/shared/{$raw}/live?from=2026-07-01&to=2026-07-31")->assertOk()->json('data');

        $names = collect($data['ads_roster'] ?? [])->pluck('name')
            ->merge(collect($data['ads'] ?? [])->pluck('name'))
            ->merge(collect($data['ads_platform_groups'] ?? [])->flatMap(fn ($p) => collect($p['groups'] ?? [])->flatMap(fn ($g) => collect($g['ads'] ?? [])->pluck('name'))))
            ->unique()->values()->all();

        $this->assertContains('Inside creative', $names, 'The granted account\'s own content is missing, so this asserts nothing.');
        $this->assertNotContains('Outside creative', $names, 'The live report listed content from an ad account the link was never scoped to.');
        $this->assertSame(1, $data['creatives_in_scope'], 'The count of what ran must describe the same ceiling as the lists.');
    }
}
