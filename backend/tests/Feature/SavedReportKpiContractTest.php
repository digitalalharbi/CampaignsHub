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
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportGenerator;
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
final class SavedReportKpiContractTest extends TestCase
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

    private function seedFour(): void
    {
        $this->spend('meta', 100.0, '2026-08-05')->update(['objective' => 'sales']);
        $this->spend('tiktok', 50.0, '2026-08-05')->update(['objective' => 'traffic']);
        $this->spend('google_ads', 70.0, '2026-08-05')->update(['objective' => 'other']);
        $this->spend('openai_ads', 30.0, '2026-08-05')->update(['objective' => 'other']);
    }

    /** @return list<string> */
    private function accountIds(): array
    {
        return DailyMetric::withoutGlobalScopes()->where('project_id', $this->project->id)->distinct()->pluck('external_account_id')->map(strval(...))->values()->all();
    }

    /** @param array<string,mixed> $scope */
    private function savedReport(array $scope, ?string $objective = null): Report
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        return Report::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Saved', 'type' => 'weekly', 'form' => 'detailed',
            'audience' => 'client', 'status' => 'processing', 'period_start' => '2026-08-03', 'period_end' => '2026-08-09', 'currency' => 'SAR',
            'campaign_objective' => $objective, 'scope' => $scope + ['account_ids' => $this->accountIds()],
        ]);
    }

    private function regenerate(Report $report): array
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        return app(ReportGenerator::class)->generate($report->fresh());
    }

    private const ALL = ['meta', 'tiktok', 'google_ads', 'openai_ads'];

    public function test_s1_sales_keeps_every_platform_whatever_its_campaign_objective_and_shows_the_seven_sales_cards(): void
    {
        $this->seedFour();
        // The shape the builder used to store: the objective inside the scope.
        $report = $this->savedReport(['objectives' => ['sales', 'conversions', 'purchases', 'add_to_cart']]);

        $data = $this->regenerate($report);

        $this->assertEqualsCanonicalizing(self::ALL, array_column($data['platforms'], 'provider'));
        $this->assertSame(['spend', 'impressions', 'clicks', 'add_to_cart', 'purchases', 'revenue', 'roas'], $data['metric_set']);
        $fresh = $report->fresh();
        $this->assertSame('sales', $fresh->campaign_objective, 'the objective moved to the report');
        $this->assertArrayNotHasKey('objectives', (array) $fresh->scope, 'and the stored scope no longer carries it as a data bound');
        $this->assertCount(4, $fresh->scope['account_ids'], 'every selected account is kept');
    }

    public function test_s2_leads_on_the_same_scope_keeps_every_platform_with_exactly_the_four_lead_cards(): void
    {
        $this->seedFour();
        $report = $this->savedReport([], 'leads');

        $data = $this->regenerate($report);

        $this->assertEqualsCanonicalizing(self::ALL, array_column($data['platforms'], 'provider'));
        $this->assertSame(['spend', 'impressions', 'clicks', 'leads'], $data['metric_set']);
    }

    public function test_every_objective_template_is_the_owners_default_and_only_a_default(): void
    {
        $this->seedFour();
        $expected = [
            'traffic' => ['spend', 'impressions', 'clicks', 'ctr', 'cpc'],
            'awareness' => ['spend', 'impressions', 'reach', 'frequency', 'cpm'],
            'engagement' => ['spend', 'impressions', 'engagements', 'engagement_rate'],
            'app_installs' => ['spend', 'impressions', 'clicks', 'installs', 'cpi'],
        ];
        foreach ($expected as $objective => $set) {
            $data = $this->regenerate($this->savedReport([], $objective));
            $this->assertSame($set, $data['metric_set'], $objective);
            $this->assertEqualsCanonicalizing(self::ALL, array_column($data['platforms'], 'provider'), "{$objective} removed a platform");
        }
    }

    public function test_s3_s4_a_custom_selection_is_exactly_what_was_chosen_survives_regeneration_and_a_change_of_objective(): void
    {
        $this->seedFour();
        $report = $this->savedReport(['metrics' => ['spend', 'impressions', 'clicks', 'roas']], 'sales');

        $this->assertSame(['spend', 'impressions', 'clicks', 'roas'], $this->regenerate($report)['metric_set']);
        $this->assertSame(['spend', 'impressions', 'clicks', 'roas'], $this->regenerate($report)['metric_set'], 'regenerating again changes nothing');

        // Change the objective: the KPI DEFAULTS change, the explicit selection and the accounts do not.
        $report->fresh()->forceFill(['campaign_objective' => 'leads'])->save();
        $after = $this->regenerate($report);
        $this->assertSame(['spend', 'impressions', 'clicks', 'roas'], $after['metric_set']);
        $this->assertEqualsCanonicalizing(self::ALL, array_column($after['platforms'], 'provider'));
        $this->assertCount(4, $report->fresh()->scope['account_ids']);
    }

    public function test_s4_editing_the_scope_through_the_api_moves_the_objective_to_the_report_and_keeps_accounts_and_metrics(): void
    {
        $this->seedFour();
        $report = $this->savedReport(['metrics' => ['spend', 'clicks']], 'sales');
        $owner = $this->owner();
        $accounts = $this->accountIds();

        $res = $this->actingAs($owner, 'sanctum')->putJson("/api/v1/projects/{$this->project->id}/reports/{$report->id}/scope", [
            'scope' => ['account_ids' => $accounts, 'objectives' => ['leads'], 'metrics' => ['spend', 'clicks']],
        ]);
        $this->assertSame(200, $res->status(), $res->getContent());

        $fresh = $report->fresh();
        $this->assertSame('leads', $fresh->campaign_objective);
        $this->assertArrayNotHasKey('objectives', (array) $fresh->scope);
        $this->assertEqualsCanonicalizing($accounts, $fresh->scope['account_ids']);
        $this->assertSame(['spend', 'clicks'], $fresh->scope['metrics']);

        // Reopening shows the objective chips from the report, for display; the stored scope is untouched.
        $shown = $this->actingAs($owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/reports/{$report->id}/scope")->json('data');
        $this->assertSame(['leads'], $shown['scope']['objectives']);
        $this->assertSame('leads', $shown['objective']);
        $this->assertArrayNotHasKey('objectives', (array) $report->fresh()->scope);

        // An EXPLICIT «filter campaigns by objective» is the only way the objective narrows the data.
        $this->actingAs($owner, 'sanctum')->putJson("/api/v1/projects/{$this->project->id}/reports/{$report->id}/scope", [
            'scope' => ['account_ids' => $accounts, 'objectives' => ['sales'], 'objective_filter' => true],
        ])->assertOk();
        $this->assertSame(['sales'], $report->fresh()->scope['objectives']);
        $this->assertTrue($report->fresh()->scope['objective_filter']);
        $this->assertSame(['meta'], array_column($this->regenerate($report)['platforms'], 'provider'));
    }

    public function test_s5_a_platform_that_sent_no_leads_or_purchases_is_not_reported_as_having_zero(): void
    {
        $this->seedFour();
        $report = $this->savedReport([], 'sales');
        $data = $this->regenerate($report);

        // Nobody sent purchases here: the reported map says so, and the page renders «غير متاح» from it instead of a zero.
        foreach ($data['reported_by_platform'] as $provider => $keys) {
            $this->assertFalse((bool) ($keys['purchases'] ?? false), "{$provider} did not report purchases");
        }
        $this->assertFalse((bool) ($data['reported']['purchases'] ?? false));
    }

    public function test_s9_another_projects_figures_never_enter_a_saved_report_even_with_the_same_accounts_selected(): void
    {
        $other = Project::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->project->client_workspace_id, 'name' => 'Other', 'status' => 'active']);
        $this->spend('meta', 100.0, '2026-08-05');
        $this->spend('meta', 5000.0, '2026-08-05', $other);

        $data = $this->regenerate($this->savedReport([], 'sales'));

        $this->assertEquals(100.0, $data['kpis']['spend']);
    }

    private function owner(): User
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O2', 'slug' => 'o2-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create(['name' => 'O', 'email' => 'kc-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return $user;
    }
}
