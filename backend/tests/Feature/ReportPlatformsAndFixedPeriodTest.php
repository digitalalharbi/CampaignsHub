<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ReportTemplateEngine;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * REPORT-PLATFORM-RECONCILE-001 and REPORT-PERIOD-MODE-001 — one authorised platform scope and one honest period, on every surface.
 *
 * Owner-confirmed on Production: a saved report showed Snapchat, TikTok and Meta while the live link showed Google Ads and ChatGPT Ads
 * too; and a weekly report with explicit dates offered 7/30/90-day filters and drifted off its saved range.
 */
final class ReportPlatformsAndFixedPeriodTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private const PROVIDERS = ['snapchat', 'tiktok', 'meta', 'google_ads', 'openai_ads'];

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    /** @var array<string, ExternalAccount> */
    private array $accounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'pf-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'pf-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        foreach (self::PROVIDERS as $provider) {
            $this->accounts[$provider] = $this->account($provider);
        }
        app(TenantContext::class)->forget();
    }

    private function account(string $provider): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: $provider,
        );

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => $provider, 'account_type' => 'ad_account',
            'external_id' => $provider.'-ad', 'name' => $provider, 'currency' => 'SAR', 'status' => 'active',
        ]);
    }

    /** One campaign on a provider with a figure on a day. */
    private function spend(string $provider, float $value, string $date, ?Project $project = null): UnifiedCampaign
    {
        $project ??= $this->project;
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $campaign = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'name' => $provider.' campaign', 'status' => 'active', 'objective' => 'sales']);
        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $this->accounts[$provider]->getKey(),
            'unified_campaign_id' => $campaign->id, 'provider' => $provider, 'external_id' => 'e-'.uniqid(), 'name' => $campaign->name, 'status' => 'active',
        ]);
        app(UpsertDailyMetrics::class)->handle([new NormalizedMetric(
            tenantId: (string) $this->tenant->id, projectId: (string) $project->id, provider: $provider,
            externalAccountId: (string) $this->accounts[$provider]->getKey(), externalCampaignId: (string) $external->id,
            unifiedCampaignId: (string) $campaign->id, metricDate: Carbon::parse($date), metricKey: 'spend', value: $value, projectCurrency: 'SAR',
        )]);
        app(TenantContext::class)->forget();

        return $campaign;
    }

    /** @param array<string,mixed> $over */
    private function liveLink(array $over = []): ReportShare
    {
        $id = $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/projects/{$this->project->id}/reports/live", array_merge([
            'name' => 'Weekly', // The accounts that actually have figures in this project: an account with none is not «part of the project» to the builder.
            'account_ids' => DailyMetric::withoutGlobalScopes()->where('project_id', $this->project->id)->distinct()->pluck('external_account_id')->map(strval(...))->values()->all(),
            'from' => '2026-08-03', 'to' => '2026-08-09',
        ], $over));
        $this->assertSame(201, $id->status(), $id->getContent());
        $id = $id->json('data.share_id');

        return ReportShare::withoutGlobalScopes()->findOrFail($id);
    }

    private function token(ReportShare $share): string
    {
        // The raw token is shown once; a test mints a second link on the same report to read through.
        [, $raw] = app(ShareService::class)->create(
            Report::withoutGlobalScopes()->findOrFail($share->report_id),
            ['scope' => $share->scope, 'mode' => 'live', 'hide_spend' => false],
            null,
        );

        return $raw;
    }

    public function test_a_scope_that_says_google_finds_the_rows_the_connector_stored_as_google_ads(): void
    {
        $this->spend('google_ads', 70.0, '2026-08-05');
        $this->spend('openai_ads', 30.0, '2026-08-05');
        $this->spend('meta', 10.0, '2026-08-05');
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $rows = app(MetricsAggregator::class)->forProjects([(string) $this->project->id])->forProviders(['google', 'chatgpt_ads'])
            ->byProvider(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        $this->assertEqualsCanonicalizing(['google_ads', 'openai_ads'], array_column($rows, 'provider'));
    }

    public function test_the_saved_layout_gains_the_platforms_that_started_reporting_after_it_was_authored(): void
    {
        $engine = app(ReportTemplateEngine::class);
        $stored = $engine->defaultConfig('sales', ['snapchat', 'tiktok', 'meta']);

        $after = $engine->reconcilePlatforms($stored, ['snapchat', 'tiktok', 'meta', 'google_ads', 'openai_ads']);

        $this->assertSame(['snapchat', 'tiktok', 'meta', 'google_ads', 'openai_ads'], $after['platform_order']);
        $slides = array_column(array_filter($after['slides'], fn ($s) => isset($s['platform'])), 'platform');
        $this->assertEqualsCanonicalizing(['snapchat', 'tiktok', 'meta', 'google_ads', 'openai_ads'], $slides);
        $this->assertContains('platform_comparison', array_column($after['slides'], 'type'));
        $orders = array_column($after['slides'], 'order');
        $this->assertSame(range(1, count($orders)), $orders, 'orders stay a clean sequence');
    }

    public function test_reconciling_keeps_the_operators_order_and_hidden_slides_and_drops_a_platform_with_no_data(): void
    {
        $engine = app(ReportTemplateEngine::class);
        $stored = $engine->defaultConfig('sales', ['snapchat', 'tiktok', 'meta']);
        $stored['platform_order'] = ['meta', 'snapchat', 'tiktok'];
        foreach ($stored['slides'] as &$s) {
            if (($s['platform'] ?? null) === 'tiktok') {
                $s['visible'] = false;
            }
        }
        unset($s);

        $after = $engine->reconcilePlatforms($stored, ['meta', 'snapchat', 'google_ads']);

        $this->assertSame(['meta', 'snapchat', 'google_ads'], $after['platform_order']);
        $platforms = array_column(array_filter($after['slides'], fn ($s) => isset($s['platform'])), 'platform');
        $this->assertNotContains('tiktok', $platforms, 'a platform with no data keeps no empty slide');
        $this->assertContains('google_ads', $platforms);
        $this->assertSame($stored['metric_set'], $after['metric_set'], 'nothing else in the config moves');
    }

    public function test_regenerating_a_saved_report_shows_every_authorised_platform(): void
    {
        foreach (['snapchat', 'tiktok', 'meta'] as $p) {
            $this->spend($p, 10.0, '2026-08-05');
        }
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $report = Report::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Week', 'type' => 'weekly', 'form' => 'detailed',
            'audience' => 'client', 'status' => 'processing', 'period_start' => '2026-08-03', 'period_end' => '2026-08-09', 'currency' => 'SAR',
        ]);
        $first = app(ReportGenerator::class)->generate($report);
        $this->assertEqualsCanonicalizing(['snapchat', 'tiktok', 'meta'], array_column($first['platforms'], 'provider'));

        // Google Ads and ChatGPT Ads report AFTER the first generation.
        $this->spend('google_ads', 40.0, '2026-08-06');
        $this->spend('openai_ads', 20.0, '2026-08-06');
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $second = app(ReportGenerator::class)->generate($report->fresh());

        $this->assertEqualsCanonicalizing(self::PROVIDERS, array_column($second['platforms'], 'provider'));
        $this->assertEqualsCanonicalizing(self::PROVIDERS, $second['platform_order']);
        $slidePlatforms = array_column(array_filter($second['slides'], fn ($s) => isset($s['platform'])), 'platform');
        $this->assertEqualsCanonicalizing(self::PROVIDERS, $slidePlatforms);
    }

    /** @param array<string, mixed> $scope */
    private function objectiveReport(array $scope): Report
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        return Report::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Sales week', 'type' => 'weekly', 'form' => 'detailed',
            'audience' => 'client', 'status' => 'processing', 'period_start' => '2026-08-03', 'period_end' => '2026-08-09', 'currency' => 'SAR',
            'scope' => $scope,
        ]);
    }

    private function seedFourObjectives(): void
    {
        $this->spend('meta', 100.0, '2026-08-05')->update(['objective' => 'sales']);
        $this->spend('tiktok', 50.0, '2026-08-05')->update(['objective' => 'traffic']);
        $this->spend('google_ads', 70.0, '2026-08-05')->update(['objective' => 'other']);
        $this->spend('openai_ads', 30.0, '2026-08-05')->update(['objective' => 'other']);
    }

    public function test_choosing_the_sales_objective_is_a_template_not_a_filter_every_platform_with_data_appears(): void
    {
        $this->seedFourObjectives();
        // Exactly what the builder stored on the Razzah report: the Sales group in the scope, and no instruction to filter.
        $report = $this->objectiveReport(['objectives' => ['sales', 'conversions', 'purchases', 'add_to_cart']]);

        $data = app(ReportGenerator::class)->generate($report);

        $this->assertEqualsCanonicalizing(['meta', 'tiktok', 'google_ads', 'openai_ads'], array_column($data['platforms'], 'provider'));
        $this->assertEquals(250.0, $data['kpis']['spend'], 'every platform counted once, none twice');
        $this->assertSame('sales', $data['objective'], 'the objective chose the sales template');
        $this->assertSame(['spend', 'impressions', 'clicks', 'add_to_cart', 'purchases', 'revenue', 'roas'], $data['metric_set'], 'the sales KPI template');
        $this->assertEqualsCanonicalizing(['meta', 'tiktok', 'google_ads', 'openai_ads'], $data['platform_order']);
    }

    public function test_campaigns_are_filtered_by_objective_only_when_the_operator_chose_to(): void
    {
        $this->seedFourObjectives();
        $report = $this->objectiveReport(['objectives' => ['sales'], 'objective_filter' => true]);

        $data = app(ReportGenerator::class)->generate($report);

        $this->assertSame(['meta'], array_column($data['platforms'], 'provider'), 'an explicit «filter campaigns by objective» keeps its strict meaning');
        $this->assertEquals(100.0, $data['kpis']['spend']);

        // The dashboard's own objective filter is unchanged.
        $strict = app(MetricsAggregator::class)->forProjects([(string) $this->project->id])->forObjectives(['sales'])->byProvider(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));
        $this->assertSame(['meta'], array_column($strict, 'provider'));
    }

    public function test_an_existing_scope_with_objectives_and_no_flag_stops_dropping_platforms_and_keeps_its_objectives(): void
    {
        $this->seedFourObjectives();
        $report = $this->objectiveReport(['objectives' => ['sales', 'conversions'], 'account_ids' => []]);

        app(ReportGenerator::class)->generate($report);

        $this->assertSame(['sales', 'conversions'], $report->fresh()->scope['objectives'], 'campaign objective classifications and the stored scope are not rewritten');
        $this->assertArrayNotHasKey('objective_filter', $report->fresh()->scope);
    }

    public function test_a_link_built_from_a_saved_report_covers_the_platforms_in_the_metrics_not_the_frozen_payload(): void
    {
        foreach (self::PROVIDERS as $p) {
            $this->spend($p, 10.0, '2026-08-05');
        }
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $report = Report::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Week', 'type' => 'weekly', 'form' => 'detailed',
            'audience' => 'client', 'status' => 'completed', 'period_start' => '2026-08-03', 'period_end' => '2026-08-09', 'currency' => 'SAR',
            // A payload frozen when only three platforms had reported.
            'data' => ['platforms' => [['provider' => 'snapchat'], ['provider' => 'tiktok'], ['provider' => 'meta']]],
        ]);
        app(TenantContext::class)->forget();

        $id = $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/projects/{$this->project->id}/reports/{$report->id}/shares", [
            'live' => true, 'mode' => 'live',
        ])->assertCreated()->json('data.id');

        $share = ReportShare::withoutGlobalScopes()->findOrFail($id);
        $this->assertEqualsCanonicalizing(self::PROVIDERS, $share->scope['providers']);
    }

    public function test_a_fixed_live_link_keeps_exactly_its_saved_dates_whatever_the_reader_asks_and_whenever_they_open_it(): void
    {
        $this->spend('meta', 100.0, '2026-08-05');   // inside the week
        $this->spend('tiktok', 900.0, '2026-08-20'); // after it: must never leak in
        $this->spend('snapchat', 700.0, '2026-07-20'); // before it
        $share = $this->liveLink(['period_mode' => 'fixed']);
        $raw = $this->token($share);

        foreach ([null, ['from' => '2026-07-01', 'to' => '2026-09-30'], ['from' => Carbon::now()->subDays(29)->toDateString(), 'to' => Carbon::now()->toDateString()]] as $ask) {
            $payload = $this->getJson("/api/v1/reports/shared/{$raw}/live".($ask === null ? '' : '?'.http_build_query($ask)))->assertOk()->json('data');
            $this->assertSame('2026-08-03', $payload['period']['from']);
            $this->assertSame('2026-08-09', $payload['period']['to']);
            $this->assertSame('fixed', $payload['period']['mode']);
            $this->assertEquals(100.0, $payload['totals']['spend'], 'only the saved week counts');
        }

        // A reader may narrow INSIDE the saved range, never widen it.
        $inside = $this->getJson("/api/v1/reports/shared/{$raw}/live?from=2026-08-04&to=2026-08-06")->assertOk()->json('data.period');
        $this->assertSame(['2026-08-04', '2026-08-06'], [$inside['from'], $inside['to']]);

        // Opened weeks later.
        Carbon::setTestNow('2026-10-01');
        $later = $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data');
        $this->assertSame('2026-08-03', $later['period']['from']);
        $this->assertSame('2026-08-09', $later['period']['to']);
        $this->assertEquals(100.0, $later['totals']['spend']);
        Carbon::setTestNow();
    }

    public function test_a_rolling_link_still_follows_the_readers_day(): void
    {
        Carbon::setTestNow('2026-08-20');
        $this->spend('meta', 100.0, '2026-08-05');
        $this->spend('tiktok', 900.0, '2026-08-18');
        $share = $this->liveLink(['period_mode' => 'rolling']);
        $raw = $this->token($share);

        $payload = $this->getJson('/api/v1/reports/shared/'.$raw.'/live?from=2026-08-14&to=2026-08-20')->assertOk()->json('data');
        $this->assertSame('rolling', $payload['period']['mode']);
        $this->assertEquals(900.0, $payload['totals']['spend']);
        Carbon::setTestNow();
    }

    public function test_the_public_page_tells_the_client_page_which_mode_the_period_is_in(): void
    {
        $this->spend('meta', 10.0, '2026-08-05');
        $fixed = $this->liveLink(['period_mode' => 'fixed']);
        $rolling = $this->liveLink(['period_mode' => 'rolling']);

        $this->assertSame('fixed', $this->getJson('/api/v1/reports/shared/'.$this->token($fixed))->assertOk()->json('data.period.mode'));
        $this->assertSame('rolling', $this->getJson('/api/v1/reports/shared/'.$this->token($rolling))->assertOk()->json('data.period.mode'));
    }

    public function test_a_link_made_before_the_mode_existed_from_a_saved_report_is_read_as_fixed_and_cannot_grow(): void
    {
        $this->spend('meta', 100.0, '2026-08-05');
        $this->spend('tiktok', 900.0, '2026-08-20');
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $report = Report::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Week', 'type' => 'weekly', 'form' => 'detailed',
            'audience' => 'client', 'status' => 'completed', 'period_start' => '2026-08-03', 'period_end' => '2026-08-09', 'currency' => 'SAR', 'data' => ['platforms' => []],
        ]);
        // The ceiling an older link was stored with: open-ended forward.
        [, $raw] = app(ShareService::class)->create($report, [
            'mode' => 'live', 'hide_spend' => false,
            'scope' => [
                'project_id' => (string) $this->project->id, 'campaign_ids' => UnifiedCampaign::withoutGlobalScopes()->where('project_id', $this->project->id)->pluck('id')->map(strval(...))->all(),
                'providers' => ['meta', 'tiktok'], 'earliest' => '2026-08-03', 'latest' => Carbon::now()->addYear()->toDateString(),
            ],
        ], null);
        app(TenantContext::class)->forget();

        $payload = $this->getJson("/api/v1/reports/shared/{$raw}/live?from=2026-07-01&to=2026-12-31")->assertOk()->json('data');

        $this->assertSame('2026-08-09', $payload['period']['to']);
        $this->assertEquals(100.0, $payload['totals']['spend']);
    }

    public function test_another_projects_figures_never_enter_a_fixed_link(): void
    {
        $other = Project::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->project->client_workspace_id, 'name' => 'Other', 'status' => 'active']);
        $this->spend('meta', 100.0, '2026-08-05');
        $this->spend('meta', 5000.0, '2026-08-05', $other);
        $raw = $this->token($this->liveLink(['period_mode' => 'fixed']));

        $payload = $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data');

        $this->assertEquals(100.0, $payload['totals']['spend']);
        $this->assertStringNotContainsString('5000', (string) json_encode($payload['totals']));
    }
}
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ReportTemplateEngine;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * REPORT-PLATFORM-RECONCILE-001 and REPORT-PERIOD-MODE-001 — one authorised platform scope and one honest period, on every surface.
 *
 * Owner-confirmed on Production: a saved report showed Snapchat, TikTok and Meta while the live link showed Google Ads and ChatGPT Ads
 * too; and a weekly report with explicit dates offered 7/30/90-day filters and drifted off its saved range.
 */
final class ReportPlatformsAndFixedPeriodTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private const PROVIDERS = ['snapchat', 'tiktok', 'meta', 'google_ads', 'openai_ads'];

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    /** @var array<string, ExternalAccount> */
    private array $accounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'pf-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'pf-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        foreach (self::PROVIDERS as $provider) {
            $this->accounts[$provider] = $this->account($provider);
        }
        app(TenantContext::class)->forget();
    }

    private function account(string $provider): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: $provider,
        );

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => $provider, 'account_type' => 'ad_account',
            'external_id' => $provider.'-ad', 'name' => $provider, 'currency' => 'SAR', 'status' => 'active',
        ]);
    }

    /** One campaign on a provider with a figure on a day. */
    private function spend(string $provider, float $value, string $date, ?Project $project = null): UnifiedCampaign
    {
        $project ??= $this->project;
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $campaign = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'name' => $provider.' campaign', 'status' => 'active', 'objective' => 'sales']);
        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $this->accounts[$provider]->getKey(),
            'unified_campaign_id' => $campaign->id, 'provider' => $provider, 'external_id' => 'e-'.uniqid(), 'name' => $campaign->name, 'status' => 'active',
        ]);
        app(UpsertDailyMetrics::class)->handle([new NormalizedMetric(
            tenantId: (string) $this->tenant->id, projectId: (string) $project->id, provider: $provider,
            externalAccountId: (string) $this->accounts[$provider]->getKey(), externalCampaignId: (string) $external->id,
            unifiedCampaignId: (string) $campaign->id, metricDate: Carbon::parse($date), metricKey: 'spend', value: $value, projectCurrency: 'SAR',
        )]);
        app(TenantContext::class)->forget();

        return $campaign;
    }

    /** @param array<string,mixed> $over */
    private function liveLink(array $over = []): ReportShare
    {
        $id = $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/projects/{$this->project->id}/reports/live", array_merge([
            'name' => 'Weekly', // The accounts that actually have figures in this project: an account with none is not «part of the project» to the builder.
            'account_ids' => DailyMetric::withoutGlobalScopes()->where('project_id', $this->project->id)->distinct()->pluck('external_account_id')->map(strval(...))->values()->all(),
            'from' => '2026-08-03', 'to' => '2026-08-09',
        ], $over));
        $this->assertSame(201, $id->status(), $id->getContent());
        $id = $id->json('data.share_id');

        return ReportShare::withoutGlobalScopes()->findOrFail($id);
    }

    private function token(ReportShare $share): string
    {
        // The raw token is shown once; a test mints a second link on the same report to read through.
        [, $raw] = app(ShareService::class)->create(
            Report::withoutGlobalScopes()->findOrFail($share->report_id),
            ['scope' => $share->scope, 'mode' => 'live', 'hide_spend' => false],
            null,
        );

        return $raw;
    }

    public function test_a_scope_that_says_google_finds_the_rows_the_connector_stored_as_google_ads(): void
    {
        $this->spend('google_ads', 70.0, '2026-08-05');
        $this->spend('openai_ads', 30.0, '2026-08-05');
        $this->spend('meta', 10.0, '2026-08-05');
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $rows = app(MetricsAggregator::class)->forProjects([(string) $this->project->id])->forProviders(['google', 'chatgpt_ads'])
            ->byProvider(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        $this->assertEqualsCanonicalizing(['google_ads', 'openai_ads'], array_column($rows, 'provider'));
    }

    public function test_the_saved_layout_gains_the_platforms_that_started_reporting_after_it_was_authored(): void
    {
        $engine = app(ReportTemplateEngine::class);
        $stored = $engine->defaultConfig('sales', ['snapchat', 'tiktok', 'meta']);

        $after = $engine->reconcilePlatforms($stored, ['snapchat', 'tiktok', 'meta', 'google_ads', 'openai_ads']);

        $this->assertSame(['snapchat', 'tiktok', 'meta', 'google_ads', 'openai_ads'], $after['platform_order']);
        $slides = array_column(array_filter($after['slides'], fn ($s) => isset($s['platform'])), 'platform');
        $this->assertEqualsCanonicalizing(['snapchat', 'tiktok', 'meta', 'google_ads', 'openai_ads'], $slides);
        $this->assertContains('platform_comparison', array_column($after['slides'], 'type'));
        $orders = array_column($after['slides'], 'order');
        $this->assertSame(range(1, count($orders)), $orders, 'orders stay a clean sequence');
    }

    public function test_reconciling_keeps_the_operators_order_and_hidden_slides_and_drops_a_platform_with_no_data(): void
    {
        $engine = app(ReportTemplateEngine::class);
        $stored = $engine->defaultConfig('sales', ['snapchat', 'tiktok', 'meta']);
        $stored['platform_order'] = ['meta', 'snapchat', 'tiktok'];
        foreach ($stored['slides'] as &$s) {
            if (($s['platform'] ?? null) === 'tiktok') {
                $s['visible'] = false;
            }
        }
        unset($s);

        $after = $engine->reconcilePlatforms($stored, ['meta', 'snapchat', 'google_ads']);

        $this->assertSame(['meta', 'snapchat', 'google_ads'], $after['platform_order']);
        $platforms = array_column(array_filter($after['slides'], fn ($s) => isset($s['platform'])), 'platform');
        $this->assertNotContains('tiktok', $platforms, 'a platform with no data keeps no empty slide');
        $this->assertContains('google_ads', $platforms);
        $this->assertSame($stored['metric_set'], $after['metric_set'], 'nothing else in the config moves');
    }

    public function test_regenerating_a_saved_report_shows_every_authorised_platform(): void
    {
        foreach (['snapchat', 'tiktok', 'meta'] as $p) {
            $this->spend($p, 10.0, '2026-08-05');
        }
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $report = Report::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Week', 'type' => 'weekly', 'form' => 'detailed',
            'audience' => 'client', 'status' => 'processing', 'period_start' => '2026-08-03', 'period_end' => '2026-08-09', 'currency' => 'SAR',
        ]);
        $first = app(ReportGenerator::class)->generate($report);
        $this->assertEqualsCanonicalizing(['snapchat', 'tiktok', 'meta'], array_column($first['platforms'], 'provider'));

        // Google Ads and ChatGPT Ads report AFTER the first generation.
        $this->spend('google_ads', 40.0, '2026-08-06');
        $this->spend('openai_ads', 20.0, '2026-08-06');
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $second = app(ReportGenerator::class)->generate($report->fresh());

        $this->assertEqualsCanonicalizing(self::PROVIDERS, array_column($second['platforms'], 'provider'));
        $this->assertEqualsCanonicalizing(self::PROVIDERS, $second['platform_order']);
        $slidePlatforms = array_column(array_filter($second['slides'], fn ($s) => isset($s['platform'])), 'platform');
        $this->assertEqualsCanonicalizing(self::PROVIDERS, $slidePlatforms);
    }

    public function test_a_link_built_from_a_saved_report_covers_the_platforms_in_the_metrics_not_the_frozen_payload(): void
    {
        foreach (self::PROVIDERS as $p) {
            $this->spend($p, 10.0, '2026-08-05');
        }
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $report = Report::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Week', 'type' => 'weekly', 'form' => 'detailed',
            'audience' => 'client', 'status' => 'completed', 'period_start' => '2026-08-03', 'period_end' => '2026-08-09', 'currency' => 'SAR',
            // A payload frozen when only three platforms had reported.
            'data' => ['platforms' => [['provider' => 'snapchat'], ['provider' => 'tiktok'], ['provider' => 'meta']]],
        ]);
        app(TenantContext::class)->forget();

        $id = $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/projects/{$this->project->id}/reports/{$report->id}/shares", [
            'live' => true, 'mode' => 'live',
        ])->assertCreated()->json('data.id');

        $share = ReportShare::withoutGlobalScopes()->findOrFail($id);
        $this->assertEqualsCanonicalizing(self::PROVIDERS, $share->scope['providers']);
    }

    public function test_a_fixed_live_link_keeps_exactly_its_saved_dates_whatever_the_reader_asks_and_whenever_they_open_it(): void
    {
        $this->spend('meta', 100.0, '2026-08-05');   // inside the week
        $this->spend('tiktok', 900.0, '2026-08-20'); // after it: must never leak in
        $this->spend('snapchat', 700.0, '2026-07-20'); // before it
        $share = $this->liveLink(['period_mode' => 'fixed']);
        $raw = $this->token($share);

        foreach ([null, ['from' => '2026-07-01', 'to' => '2026-09-30'], ['from' => Carbon::now()->subDays(29)->toDateString(), 'to' => Carbon::now()->toDateString()]] as $ask) {
            $payload = $this->getJson("/api/v1/reports/shared/{$raw}/live".($ask === null ? '' : '?'.http_build_query($ask)))->assertOk()->json('data');
            $this->assertSame('2026-08-03', $payload['period']['from']);
            $this->assertSame('2026-08-09', $payload['period']['to']);
            $this->assertSame('fixed', $payload['period']['mode']);
            $this->assertEquals(100.0, $payload['totals']['spend'], 'only the saved week counts');
        }

        // A reader may narrow INSIDE the saved range, never widen it.
        $inside = $this->getJson("/api/v1/reports/shared/{$raw}/live?from=2026-08-04&to=2026-08-06")->assertOk()->json('data.period');
        $this->assertSame(['2026-08-04', '2026-08-06'], [$inside['from'], $inside['to']]);

        // Opened weeks later.
        Carbon::setTestNow('2026-10-01');
        $later = $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data');
        $this->assertSame('2026-08-03', $later['period']['from']);
        $this->assertSame('2026-08-09', $later['period']['to']);
        $this->assertEquals(100.0, $later['totals']['spend']);
        Carbon::setTestNow();
    }

    public function test_a_rolling_link_still_follows_the_readers_day(): void
    {
        Carbon::setTestNow('2026-08-20');
        $this->spend('meta', 100.0, '2026-08-05');
        $this->spend('tiktok', 900.0, '2026-08-18');
        $share = $this->liveLink(['period_mode' => 'rolling']);
        $raw = $this->token($share);

        $payload = $this->getJson('/api/v1/reports/shared/'.$raw.'/live?from=2026-08-14&to=2026-08-20')->assertOk()->json('data');
        $this->assertSame('rolling', $payload['period']['mode']);
        $this->assertEquals(900.0, $payload['totals']['spend']);
        Carbon::setTestNow();
    }

    public function test_the_public_page_tells_the_client_page_which_mode_the_period_is_in(): void
    {
        $this->spend('meta', 10.0, '2026-08-05');
        $fixed = $this->liveLink(['period_mode' => 'fixed']);
        $rolling = $this->liveLink(['period_mode' => 'rolling']);

        $this->assertSame('fixed', $this->getJson('/api/v1/reports/shared/'.$this->token($fixed))->assertOk()->json('data.period.mode'));
        $this->assertSame('rolling', $this->getJson('/api/v1/reports/shared/'.$this->token($rolling))->assertOk()->json('data.period.mode'));
    }

    public function test_a_link_made_before_the_mode_existed_from_a_saved_report_is_read_as_fixed_and_cannot_grow(): void
    {
        $this->spend('meta', 100.0, '2026-08-05');
        $this->spend('tiktok', 900.0, '2026-08-20');
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $report = Report::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Week', 'type' => 'weekly', 'form' => 'detailed',
            'audience' => 'client', 'status' => 'completed', 'period_start' => '2026-08-03', 'period_end' => '2026-08-09', 'currency' => 'SAR', 'data' => ['platforms' => []],
        ]);
        // The ceiling an older link was stored with: open-ended forward.
        [, $raw] = app(ShareService::class)->create($report, [
            'mode' => 'live', 'hide_spend' => false,
            'scope' => [
                'project_id' => (string) $this->project->id, 'campaign_ids' => UnifiedCampaign::withoutGlobalScopes()->where('project_id', $this->project->id)->pluck('id')->map(strval(...))->all(),
                'providers' => ['meta', 'tiktok'], 'earliest' => '2026-08-03', 'latest' => Carbon::now()->addYear()->toDateString(),
            ],
        ], null);
        app(TenantContext::class)->forget();

        $payload = $this->getJson("/api/v1/reports/shared/{$raw}/live?from=2026-07-01&to=2026-12-31")->assertOk()->json('data');

        $this->assertSame('2026-08-09', $payload['period']['to']);
        $this->assertEquals(100.0, $payload['totals']['spend']);
    }

    public function test_another_projects_figures_never_enter_a_fixed_link(): void
    {
        $other = Project::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->project->client_workspace_id, 'name' => 'Other', 'status' => 'active']);
        $this->spend('meta', 100.0, '2026-08-05');
        $this->spend('meta', 5000.0, '2026-08-05', $other);
        $raw = $this->token($this->liveLink(['period_mode' => 'fixed']));

        $payload = $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data');

        $this->assertEquals(100.0, $payload['totals']['spend']);
        $this->assertStringNotContainsString('5000', (string) json_encode($payload['totals']));
    }
}
