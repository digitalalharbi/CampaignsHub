<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Jobs\FetchPeriodReachJob;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\PeriodReach;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Notifications\Services\DigestPresenter;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REACH-PERIOD-001 — the cards show the provider's reach for the window, not «—», where one exists.
 *
 * Two days of Meta delivery for one campaign: the daily reach rows add up to 9,000, which is not
 * reach (#494 made that «—»). Meta's own answer for the two-day window is 6,000 — the people it
 * reached across both days, counted once — and that is what every card reads, with frequency =
 * impressions ÷ 6,000.
 */
final class PeriodReachOnTheCardsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalAccount $account;

    private ExternalCampaign $external;

    private UnifiedCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        foreach (PlatformCredentials::for('meta')->requires() as $key) {
            config()->set("ad_platforms.platforms.meta.{$key}", "test-{$key}");
        }

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'pr-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId($this->project->id);

        $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: 'meta', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'meta');
        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => 'meta',
            'account_type' => 'ad_account', 'external_id' => 'act_9', 'name' => 'Meta', 'status' => 'active',
            'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'discovered_at' => Carbon::now(),
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->account->id,
            'provider' => 'meta', 'purpose' => 'advertising', 'is_active' => true, 'campaign_management_enabled' => true,
        ]);

        $this->campaign = UnifiedCampaign::create(['project_id' => $this->project->id, 'name' => 'Brand', 'status' => 'active', 'objective' => 'reach']);
        $this->external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->account->id,
            'unified_campaign_id' => $this->campaign->id, 'provider' => 'meta', 'external_id' => 'cmp-9', 'name' => 'Brand', 'status' => 'active',
        ]);

        $this->day($this->external->id, '2026-07-10', ['impressions' => 8_000, 'reach' => 5_000, 'spend' => 10]);
        $this->day($this->external->id, '2026-07-11', ['impressions' => 4_000, 'reach' => 4_000, 'spend' => 10]);
    }

    /** @param array<string,float> $figures */
    private function day(string $externalCampaignId, string $date, array $figures, ?string $account = null, ?string $campaign = null, ?string $project = null): void
    {
        foreach ($figures as $key => $value) {
            DailyMetric::withoutGlobalScopes()->create([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project ?? $this->project->id,
                'external_account_id' => $account ?? $this->account->id, 'external_campaign_id' => $externalCampaignId,
                'unified_campaign_id' => $campaign ?? $this->campaign->id, 'provider' => 'meta',
                'metric_key' => $key, 'metric_date' => $date, 'value' => $value,
            ]);
        }
    }

    private function providerAnswered(string $grain, string $entity, float $reach, string $from = '2026-07-10', string $to = '2026-07-11'): void
    {
        PeriodReach::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'external_account_id' => $this->account->id, 'provider' => 'meta',
            'grain' => $grain, 'external_entity_id' => $entity,
            'external_campaign_id' => $grain === PeriodReach::CAMPAIGN ? $this->external->id : null,
            'date_from' => $from, 'date_to' => $to, 'reach' => $reach, 'state' => PeriodReach::REPORTED, 'fetched_at' => Carbon::now(),
        ]);
    }

    private function engine(): MetricsAggregator
    {
        return app(MetricsAggregator::class);
    }

    private function windowFrom(): Carbon
    {
        return Carbon::parse('2026-07-10');
    }

    private function windowTo(): Carbon
    {
        return Carbon::parse('2026-07-11');
    }

    public function test_the_totals_card_reads_the_providers_reach_for_the_window_and_frequency_from_it(): void
    {
        $this->providerAnswered(PeriodReach::ACCOUNT, 'act_9', 6_000);
        $this->providerAnswered(PeriodReach::CAMPAIGN, 'cmp-9', 6_000);

        $totals = $this->engine()->totals($this->windowFrom(), $this->windowTo());

        $this->assertSame(6_000.0, (float) $totals['reach'], 'not the 9,000 the days add up to');
        $this->assertSame(2.0, (float) $totals['frequency'], '12,000 impressions ÷ 6,000');
        $this->assertTrue($this->engine()->reportedKeys($this->windowFrom(), $this->windowTo())['reach']);
    }

    public function test_the_platform_and_campaign_rows_read_it_too(): void
    {
        $this->providerAnswered(PeriodReach::ACCOUNT, 'act_9', 6_000);
        $this->providerAnswered(PeriodReach::CAMPAIGN, 'cmp-9', 5_900);

        $meta = collect($this->engine()->byProvider($this->windowFrom(), $this->windowTo()))->firstWhere('provider', 'meta');
        // One campaign in scope: the campaign's own window reach is the closest answer to the scope.
        $this->assertSame(5_900.0, (float) $meta['reach']);

        $campaign = collect($this->engine()->byCampaign($this->windowFrom(), $this->windowTo()))->firstWhere('campaign_id', $this->campaign->id);
        $this->assertSame(5_900.0, (float) $campaign['reach']);
    }

    public function test_an_account_figure_is_not_used_where_the_scope_is_only_part_of_that_account(): void
    {
        // Another project's campaign on the same ad account: the account's reach counts its audience too.
        $otherProject = Project::create(['client_workspace_id' => $this->project->client_workspace_id, 'name' => 'Other', 'status' => 'active']);
        $otherCampaign = UnifiedCampaign::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $otherProject->id, 'name' => 'Theirs', 'status' => 'active', 'objective' => 'reach']);
        $otherExternal = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $otherProject->id, 'external_account_id' => $this->account->id,
            'unified_campaign_id' => $otherCampaign->id, 'provider' => 'meta', 'external_id' => 'cmp-other', 'name' => 'Theirs', 'status' => 'active',
        ]);
        $this->day($otherExternal->id, '2026-07-10', ['impressions' => 1_000, 'reach' => 900], campaign: $otherCampaign->id, project: $otherProject->id);

        // Two campaigns of ours would need the account figure; only the account figure exists.
        $second = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->account->id,
            'unified_campaign_id' => $this->campaign->id, 'provider' => 'meta', 'external_id' => 'cmp-10', 'name' => 'Brand 2', 'status' => 'active',
        ]);
        $this->day($second->id, '2026-07-10', ['impressions' => 2_000, 'reach' => 1_500]);
        $this->providerAnswered(PeriodReach::ACCOUNT, 'act_9', 8_000);

        $totals = $this->engine()->totals($this->windowFrom(), $this->windowTo());

        $this->assertNull($totals['reach'], 'the account reach includes another project\'s audience');
        $this->assertNull($totals['frequency']);
    }

    public function test_a_window_nobody_has_fetched_reads_not_reported_and_is_requested(): void
    {
        $this->providerAnswered(PeriodReach::ACCOUNT, 'act_9', 6_000, '2026-07-01', '2026-07-31');

        $totals = $this->engine()->totals($this->windowFrom(), $this->windowTo());

        $this->assertNull($totals['reach'], 'a figure for another window is not this window\'s reach');
        Queue::assertPushed(FetchPeriodReachJob::class, fn (FetchPeriodReachJob $job): bool => $job->accountId === $this->account->id
            && $job->from === '2026-07-10' && $job->to === '2026-07-11');
    }

    public function test_a_provider_without_period_reach_is_not_asked_and_stays_not_reported(): void
    {
        $this->account->forceFill(['provider' => 'tiktok'])->save();

        $this->assertNull($this->engine()->totals($this->windowFrom(), $this->windowTo())['reach']);
        Queue::assertNotPushed(FetchPeriodReachJob::class);
    }

    public function test_the_live_link_report_snapshot_and_digest_read_the_same_reach(): void
    {
        $this->providerAnswered(PeriodReach::ACCOUNT, 'act_9', 6_000);
        $this->providerAnswered(PeriodReach::CAMPAIGN, 'cmp-9', 6_000);

        $report = Report::create([
            'project_id' => $this->project->id, 'name' => 'R', 'type' => 'monthly', 'status' => 'processing',
            'currency' => 'SAR', 'period_start' => '2026-07-10', 'period_end' => '2026-07-11',
        ]);
        (new GenerateReportJob((string) $report->id))->handle(app(ReportGenerator::class));
        $data = $report->refresh()->data;
        $this->assertSame(6_000.0, (float) $data['kpis']['reach']);
        $this->assertTrue($data['reported']['reach']);

        $presenter = app(DigestPresenter::class);
        $this->assertSame('6,000', $presenter->count($this->engine()->totals($this->windowFrom(), $this->windowTo()), $this->engine()->reportedKeys($this->windowFrom(), $this->windowTo()), 'reach'));

        [, $raw] = app(ShareService::class)->create($report, ['scope' => [
            'project_id' => $this->project->id, 'campaign_ids' => [$this->campaign->id], 'providers' => ['meta'],
            'earliest' => '2026-07-10', 'latest' => '2026-07-11',
        ]], null);
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        $res = $this->getJson("/api/v1/reports/shared/{$raw}/live?from=2026-07-10&to=2026-07-11")->assertOk();
        $this->assertSame(6_000.0, (float) $res->json('data.totals.reach'));
        $this->assertSame(2.0, (float) $res->json('data.totals.frequency'));
    }

    public function test_a_sync_re_requests_the_stored_windows_it_may_have_changed_and_no_others(): void
    {
        $this->providerAnswered(PeriodReach::ACCOUNT, 'act_9', 6_000, '2026-07-01', '2026-07-14');
        $this->providerAnswered(PeriodReach::ACCOUNT, 'act_9', 3_000, '2026-06-01', '2026-06-30');
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);

        app(AccountMetricsSyncer::class)->sync($this->account, Carbon::parse('2026-07-10'), Carbon::parse('2026-07-11'));

        Queue::assertPushed(FetchPeriodReachJob::class, fn (FetchPeriodReachJob $job): bool => $job->from === '2026-07-01' && $job->to === '2026-07-14');
        Queue::assertNotPushed(FetchPeriodReachJob::class, fn (FetchPeriodReachJob $job): bool => $job->from === '2026-06-01');
    }
}
