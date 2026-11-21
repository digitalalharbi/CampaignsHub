<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * KPI-PARITY-001 — one definition per metric, on every surface that prints it.
 *
 * The world: one project, two platforms (meta, snapchat), two campaigns (one per platform, a leads
 * campaign and a sales campaign), one creative, ten days of figures — plus a DEMO row and an
 * account-level row (no unified campaign) that a second query is likely to count differently.
 *
 * The surfaces: the dashboard and the analytics tab (both `metrics/summary`, so they are one reader
 * by construction and asserted as such), the analytics breakdowns (`metrics/platforms`,
 * `metrics/campaigns`, `metrics/objective-performance`), and the client's live link (`totals`,
 * `platforms`, `campaign_rows`, `objective_performance`, `results`).
 *
 * The rule: for the same project and window every total is identical to the digit; a figure that is
 * LEGITIMATELY computed differently (configured Results, the sales-only direct split) must carry a
 * `basis`/`formula` label in the payload so no page can print it as if it were the headline figure.
 */
final class KpiDefinitionParityTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-07-01';

    private const TO = '2026-07-10';

    private const WINDOW = 'from=2026-07-01&to=2026-07-10';

    /** Totals the base metrics must sum to across BOTH campaigns over the ten days — only the headline keys are listed. */
    private const EXPECTED = ['spend' => 2000.0, 'impressions' => 150000.0, 'clicks' => 3500.0, 'conversions' => 125.0, 'leads' => 80.0, 'revenue' => 6000.0];

    private const KEYS = ['spend', 'impressions', 'clicks', 'conversions', 'leads', 'revenue'];

    private const DERIVED = ['cpa', 'cpl', 'ctr', 'roas', 'cpc'];

    private Tenant $tenant;

    private Project $project;

    private User $operator;

    private UnifiedCampaign $leads;

    private UnifiedCampaign $sales;

    /** @var array<string, ExternalAccount> */
    private array $accounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'K', 'slug' => 'k-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create(['name' => 'O', 'email' => 'parity-'.uniqid().'@k.test', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $workspace = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active']);
        // RESULT-EVENTS-001 — the project says what a Result is per platform.
        $this->project->forceFill(['meta' => ['result_events' => ['meta' => ['all' => 'leads'], 'snapchat' => ['all' => 'conversions']]]])->save();

        foreach (['meta', 'snapchat'] as $provider) {
            $this->accounts[$provider] = $this->account($provider, $workspace);
        }

        $this->leads = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Leads', 'status' => 'active', 'objective' => 'leads']);
        $this->sales = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Sales', 'status' => 'active', 'objective' => 'sales']);

        $metaExternal = $this->external($this->leads, 'meta');
        $snapExternal = $this->external($this->sales, 'snapchat');

        $rows = [];
        for ($day = 0; $day < 10; $day++) {
            $date = Carbon::parse(self::FROM)->addDays($day);
            // Leads campaign on Meta: 80 spend, 5 000 impressions, 150 clicks, 5 conversions, 8 leads, no revenue.
            foreach (['spend' => 80.0, 'impressions' => 5000.0, 'clicks' => 150.0, 'conversions' => 5.0, 'leads' => 8.0] as $key => $value) {
                $rows[] = $this->metric($this->leads, $metaExternal, 'meta', $key, $value, $date);
            }
            // Sales campaign on Snapchat: 120 spend, 10 000 impressions, 200 clicks, 7.5 conversions, 600 revenue.
            foreach (['spend' => 120.0, 'impressions' => 10000.0, 'clicks' => 200.0, 'conversions' => 7.5, 'revenue' => 600.0] as $key => $value) {
                $rows[] = $this->metric($this->sales, $snapExternal, 'snapchat', $key, $value, $date);
            }
        }
        // A DEMO row inside the window: the aggregator excludes it once real rows exist, so must every other reader.
        $rows[] = $this->metric($this->sales, $this->external($this->sales, 'snapchat'), 'snapchat', 'spend', 999.0, Carbon::parse(self::FROM), demo: true);
        app(UpsertDailyMetrics::class)->handle($rows);

        ExternalCreative::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'campaign_id' => $this->sales->id,
            'external_campaign_id' => $snapExternal->getKey(), 'provider' => 'snapchat', 'external_creative_id' => 'cr-'.uniqid(),
            'name' => 'Creative', 'format' => 'image', 'status' => 'active', 'asset_url' => 'https://cdn.example/c.jpg', 'source_type' => 'sync',
        ]);

        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
    }

    // ── one reader ──────────────────────────────────────────────────────────────────────────────

    public function test_dashboard_analytics_and_the_live_link_state_identical_totals(): void
    {
        $summary = $this->read('metrics/summary')->json('data.current');
        $live = $this->live();

        foreach (self::KEYS as $key) {
            $this->assertSame(self::EXPECTED[$key], round((float) $summary[$key], 2), "summary.{$key} is not the seeded total");
            $this->assertSame(round((float) $summary[$key], 2), round((float) $live['totals'][$key], 2), "live totals.{$key} differs from the dashboard/analytics summary");
        }
        foreach (self::DERIVED as $key) {
            $this->assertEquals($summary[$key], $live['totals'][$key], "live totals.{$key} is derived by another formula than the summary's");
        }
        // The headline conversions figure says what it is on both surfaces, in the same words.
        $this->assertSame($this->read('metrics/summary')->json('data.conversions_basis'), $live['conversions_basis']);
    }

    public function test_the_platform_breakdowns_sum_to_the_totals_on_both_surfaces(): void
    {
        $summary = $this->read('metrics/summary')->json('data.current');
        $platforms = $this->read('metrics/platforms')->json('data');
        $live = $this->live();

        $this->assertCount(2, $platforms);
        $this->assertCount(2, $live['platforms']);
        foreach (self::KEYS as $key) {
            $this->assertSame(round((float) $summary[$key], 2), $this->sum($platforms, $key), "analytics platforms do not sum to summary.{$key}");
            $this->assertSame(round((float) $live['totals'][$key], 2), $this->sum($live['platforms'], $key), "live platforms do not sum to totals.{$key}");
        }
        // The same platform row on both surfaces, to the digit.
        foreach ($platforms as $row) {
            $twin = collect($live['platforms'])->firstWhere('provider', $row['provider']);
            $this->assertNotNull($twin);
            foreach ([...self::KEYS, ...self::DERIVED] as $key) {
                $this->assertEquals($row[$key], $twin[$key], "platform {$row['provider']}.{$key} differs between analytics and the live link");
            }
        }
    }

    public function test_the_campaign_breakdowns_sum_to_the_totals_on_both_surfaces(): void
    {
        $summary = $this->read('metrics/summary')->json('data.current');
        $campaigns = $this->read('metrics/campaigns')->json('data');
        $live = $this->live();

        $this->assertCount(2, $campaigns);
        $this->assertCount(2, $live['campaign_rows'], 'the client link carries one client-safe row per campaign');
        foreach (['spend', 'impressions', 'clicks'] as $key) {
            $this->assertSame(round((float) $summary[$key], 2), $this->sum($campaigns, $key), "analytics campaigns do not sum to summary.{$key}");
            $this->assertSame(round((float) $live['totals'][$key], 2), $this->sum($live['campaign_rows'], $key), "live campaign_rows do not sum to totals.{$key}");
        }
    }

    public function test_the_objective_split_is_built_on_the_same_rows_as_the_totals(): void
    {
        $summary = $this->read('metrics/summary')->json('data.current');
        $objective = $this->read('metrics/objective-performance')->json('data');
        $live = $this->live();

        // Blended = the whole programme = the headline spend the analytics tab AND the client link print.
        $this->assertSame(round((float) $summary['spend'], 2), round((float) $objective['blended']['spend'], 2), 'the objective split counts spend the totals do not (demo rows / joins)');
        $this->assertSame(round((float) $live['totals']['spend'], 2), round((float) $objective['blended']['spend'], 2));
        $this->assertSame(round((float) $summary['spend'], 2), round($this->sum($objective['paths'], 'spend'), 2), 'the paths do not sum to the headline spend');

        // The direct split is a DIFFERENT (sales-only) formula and it says so — never a silent second CPA.
        $this->assertSame(1200.0, round((float) $objective['direct']['spend'], 2));
        $this->assertNotEmpty($objective['direct']['formula']['cpa']);
        $this->assertNotEmpty($objective['blended']['formula']['blended_cpa']);
        // REPORT-SECTION-STREAMS-001 — the split is buying methodology and never reaches a client link; this test does not reopen that.
        $this->assertArrayNotHasKey('objective_performance', $live);
    }

    public function test_the_objective_split_honours_the_same_platform_filter_as_the_summary(): void
    {
        $summary = $this->read('metrics/summary', '&provider=meta')->json('data.current');
        $objective = $this->read('metrics/objective-performance', '&provider=meta')->json('data');

        $this->assertSame(800.0, round((float) $summary['spend'], 2));
        $this->assertSame(round((float) $summary['spend'], 2), round((float) $objective['blended']['spend'], 2), 'the objective split ignored the platform filter the summary applied');
    }

    // ── labelled differences ────────────────────────────────────────────────────────────────────

    public function test_configured_results_differ_from_conversions_and_are_labelled_as_such(): void
    {
        $live = $this->live();
        $results = $live['results'];

        $this->assertTrue($results['configured']);
        // Meta's Result is `leads` (80), Snapchat's is `conversions` (75): 155, which is NOT the 125 conversions headline.
        $this->assertSame(155.0, (float) $results['total']);
        $this->assertNotSame((float) $live['totals']['conversions'], (float) $results['total']);
        $this->assertSame(round(2000.0 / 155.0, 4), (float) $results['cost']);
        // …and the payload says which definition produced it, so no page can print it as «conversions».
        $this->assertArrayHasKey('basis', $results, 'a Result total that is not the conversions total must carry its basis');
        $this->assertSame('configured_result_events', $results['basis']);
        $this->assertArrayHasKey('formula', $results);
        $this->assertSame(['meta' => ['all' => 'leads'], 'snapchat' => ['all' => 'conversions']], $results['events']);
    }

    public function test_content_figures_name_their_grain(): void
    {
        $this->holdingTenant((string) $this->tenant->id);
        $creative = ExternalCreative::withoutGlobalScopes()->where('project_id', $this->project->id)->firstOrFail();
        foreach ([self::FROM, '2026-07-02'] as $date) {
            DB::table('creative_daily_metrics')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                'creative_id' => $creative->getKey(), 'campaign_id' => $this->sales->id, 'metric_date' => $date,
                'spend' => 30.0, 'impressions' => 1000.0, 'clicks' => 10.0, 'conversions' => 2.0, 'revenue' => 0,
                'video_views' => 0, 'video_completions' => 0, 'is_demo' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        app(TenantContext::class)->forget();

        $row = collect((array) $this->read('creatives')->json('data.creatives'))->firstWhere('id', (string) $creative->getKey());
        $this->assertNotNull($row, 'the content library did not return the creative');
        // Content is a different GRAIN (ad/creative rows), not a different formula — and the row says which.
        $this->assertContains($row['metrics']['grain'] ?? null, ['ad', 'creative'], 'a content row must label the grain its figures are read at');
        $this->assertSame(60.0, round((float) $row['metrics']['spend'], 2));
    }

    // ── world ───────────────────────────────────────────────────────────────────────────────────

    private function read(string $path, string $extra = ''): TestResponse
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/{$path}?".self::WINDOW.$extra)
            ->assertOk();
    }

    /** @return array<string, mixed> */
    private function live(): array
    {
        $this->holdingTenant((string) $this->tenant->id);
        $report = Report::create([
            'project_id' => $this->project->id, 'name' => 'R', 'type' => 'executive', 'status' => 'completed',
            'currency' => 'SAR', 'period_start' => self::FROM, 'period_end' => self::TO, 'data' => ['kpis' => ['spend' => 1]],
        ]);
        [, $raw] = app(ShareService::class)->create($report, [
            'hide_spend' => false,
            'settings' => ['campaign_breakdown' => true],
            'scope' => [
                'project_id' => $this->project->id,
                'campaign_ids' => [$this->leads->id, $this->sales->id],
                'providers' => ['meta', 'snapchat'],
                'earliest' => self::FROM,
                'latest' => self::TO,
            ],
        ], null);
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        return $this->getJson("/api/v1/reports/shared/{$raw}/live?".self::WINDOW)->assertOk()->json('data');
    }

    /** @param array<int, array<string, mixed>>|null $rows */
    private function sum(?array $rows, string $key): float
    {
        return round(array_sum(array_map(static fn (array $row): float => (float) ($row[$key] ?? 0), $rows ?? [])), 2);
    }

    private function metric(UnifiedCampaign $campaign, ExternalCampaign $external, string $provider, string $key, float $value, Carbon $date, bool $demo = false): NormalizedMetric
    {
        return new NormalizedMetric(
            tenantId: (string) $this->tenant->id,
            projectId: (string) $this->project->id,
            provider: $provider,
            externalAccountId: (string) $this->accounts[$provider]->getKey(),
            externalCampaignId: (string) $external->getKey(),
            unifiedCampaignId: (string) $campaign->id,
            metricDate: $date,
            metricKey: $key,
            value: $value,
            originalCurrency: 'SAR',
            projectCurrency: 'SAR',
            exchangeRate: 1.0,
            originalTimezone: 'UTC',
            projectTimezone: 'Asia/Riyadh',
            attributionWindow: 'default',
            sourceType: 'api',
            dataFreshnessAt: $date->copy()->endOfDay(),
            isDemo: $demo,
        );
    }

    private function external(UnifiedCampaign $campaign, string $provider): ExternalCampaign
    {
        return ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->accounts[$provider]->getKey(), 'unified_campaign_id' => $campaign->id,
            'provider' => $provider, 'external_id' => 'ext-'.uniqid(), 'name' => $campaign->name, 'status' => 'active',
        ]);
    }

    private function account(string $provider, ClientWorkspace $workspace): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: $provider,
        );
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => $provider, 'account_type' => 'ad_account',
            'external_id' => "{$provider}-ad", 'name' => ucfirst($provider), 'currency' => 'SAR', 'status' => 'active',
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'project_id' => $this->project->id,
            'external_account_id' => $account->id, 'provider' => $provider, 'purpose' => 'advertising', 'is_active' => true,
        ]);

        return $account;
    }
}
