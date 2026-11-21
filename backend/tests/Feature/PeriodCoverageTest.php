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
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BindsSeededAccounts;
use Tests\TestCase;

/**
 * DATE-RANGE-001 §4 — a missing day inside the period is reported per account, from the sync history.
 *
 * Fixed clock: 2026-11-15 (Riyadh). The causes this endpoint may name are exactly two — `no_rows` and
 * `sync_failed` — because they are all the history holds; it never guesses why.
 */
final class PeriodCoverageTest extends TestCase
{
    use BindsSeededAccounts;
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $operator;

    private ExternalAccount $account;

    private ExternalCampaign $external;

    private UnifiedCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-15 09:00:00', 'Asia/Riyadh'));
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create(['name' => 'O', 'email' => 'o@p.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $workspace = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active']);

        $this->account = $this->account('snapchat', 'Asia/Riyadh');
        $this->bindSeededAccount((string) $this->project->id, $this->account->getKey());

        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'حملة',
            'status' => 'active', 'objective' => 'sales', 'total_budget' => 1000, 'budget_currency' => 'SAR',
        ]);
        $this->external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->account->getKey(), 'unified_campaign_id' => $this->campaign->id,
            'provider' => 'snapchat', 'external_id' => 'ext-1', 'name' => 'حملة', 'status' => 'active',
        ]);

        app(TenantContext::class)->forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_day_with_no_rows_inside_the_range_is_named(): void
    {
        $this->seedDays(['2026-11-08', '2026-11-09', '2026-11-10', '2026-11-12', '2026-11-13', '2026-11-14']);

        $r = $this->coverage('2026-11-08', '2026-11-14')->assertOk()->json('data');

        $this->assertTrue($r['incomplete']);
        $this->assertFalse($r['ongoing']);
        $acc = $r['accounts'][0];
        $this->assertSame(['2026-11-11'], $acc['missing_days']);
        $this->assertSame([], $acc['failed_days']);
        $this->assertSame(1, $r['summary']['missing_days_total']);
    }

    public function test_a_failed_sync_covering_the_missing_day_is_reported_as_failed_and_nothing_more(): void
    {
        $this->seedDays(['2026-11-08', '2026-11-09', '2026-11-10', '2026-11-12', '2026-11-13', '2026-11-14']);
        $this->syncRun('failed', '2026-11-11', '2026-11-11');

        $acc = $this->coverage('2026-11-08', '2026-11-14')->assertOk()->json('data.accounts.0');

        $this->assertSame(['2026-11-11'], $acc['failed_days']);
        $this->assertArrayNotHasKey('error', $acc, 'The cause is the recorded status; no error text is passed on or invented.');
    }

    public function test_a_day_the_platform_answered_empty_is_not_missing(): void
    {
        $this->seedDays(['2026-11-08', '2026-11-09', '2026-11-10', '2026-11-12', '2026-11-13', '2026-11-14']);
        $this->syncRun('no_data', '2026-11-11', '2026-11-11');

        $r = $this->coverage('2026-11-08', '2026-11-14')->assertOk()->json('data');

        $this->assertFalse($r['incomplete']);
    }

    public function test_a_complete_range_is_complete(): void
    {
        $this->seedDays(['2026-11-08', '2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14']);

        $this->assertFalse($this->coverage('2026-11-08', '2026-11-14')->json('data.incomplete'));
    }

    public function test_a_range_containing_today_is_ongoing_and_today_is_not_a_gap(): void
    {
        $this->seedDays(['2026-11-13', '2026-11-14']);

        $r = $this->coverage('2026-11-13', '2026-11-15')->assertOk()->json('data');

        $this->assertTrue($r['ongoing']);
        $this->assertFalse($r['incomplete'], 'Today has no figures yet and that is not a missing day.');
    }

    public function test_days_before_the_accounts_first_row_are_not_missing(): void
    {
        $this->seedDays(['2026-11-12', '2026-11-13', '2026-11-14']);

        $this->assertFalse($this->coverage('2026-11-01', '2026-11-14')->json('data.incomplete'));
    }

    public function test_an_account_in_another_time_zone_is_flagged(): void
    {
        $this->seedDays(['2026-11-14']);
        DB::table('external_accounts')->where('id', $this->account->getKey())->update(['timezone' => 'America/Los_Angeles']);

        $acc = $this->coverage('2026-11-14', '2026-11-14')->assertOk()->json('data.accounts.0');

        $this->assertTrue($acc['timezone_differs']);
        $this->assertSame('America/Los_Angeles', $acc['timezone']);
    }

    public function test_an_account_in_riyadh_time_is_not_flagged(): void
    {
        $this->seedDays(['2026-11-14']);

        $this->assertFalse($this->coverage('2026-11-14', '2026-11-14')->json('data.accounts.0.timezone_differs'));
    }

    public function test_the_period_endpoint_resolves_a_named_period_and_its_comparison(): void
    {
        $r = $this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url('metrics/period?period=last_30&compare=previous'))->assertOk()->json('data');

        $this->assertSame(['2026-10-16', '2026-11-14'], [$r['period']['from'], $r['period']['to']]);
        $this->assertSame(['2026-09-16', '2026-10-15'], [$r['comparison']['from'], $r['comparison']['to']]);
        $this->assertSame('Asia/Riyadh', $r['timezone']);
    }

    public function test_a_viewer_without_the_project_is_refused(): void
    {
        $stranger = User::create(['name' => 'S', 'email' => 's@p.test', 'password' => 'secret123']);

        $this->actingAs($stranger, 'sanctum')->getJson($this->url('metrics/period-coverage?from=2026-11-08&to=2026-11-14'))->assertStatus(403);
    }

    private function coverage(string $from, string $to): TestResponse
    {
        return $this->actingAs($this->operator, 'sanctum')->getJson($this->url("metrics/period-coverage?from={$from}&to={$to}"));
    }

    private function url(string $path): string
    {
        return "/api/v1/projects/{$this->project->id}/{$path}";
    }

    private function syncRun(string $status, string $from, string $to): void
    {
        MetricSyncRun::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->account->getKey(),
            'provider' => 'snapchat', 'status' => $status, 'window_start' => $from, 'window_end' => $to,
            'attempts' => 1, 'started_at' => Carbon::now(), 'finished_at' => Carbon::now(),
        ]);
    }

    /** @param list<string> $dates */
    private function seedDays(array $dates): void
    {
        $this->holdingTenant((string) $this->tenant->id);

        app(UpsertDailyMetrics::class)->handle(array_map(fn (string $date): NormalizedMetric => new NormalizedMetric(
            tenantId: (string) $this->tenant->id, projectId: (string) $this->project->id, provider: 'snapchat',
            externalAccountId: (string) $this->account->getKey(), externalCampaignId: (string) $this->external->getKey(),
            unifiedCampaignId: (string) $this->campaign->id, metricDate: Carbon::parse($date), metricKey: 'spend', value: 10.0,
            originalCurrency: 'SAR', projectCurrency: 'SAR', exchangeRate: 1.0, originalTimezone: 'Asia/Riyadh',
            projectTimezone: 'Asia/Riyadh', attributionWindow: '7d_click', sourceType: 'api', dataFreshnessAt: Carbon::parse($date)->endOfDay(),
        ), $dates));

        app(TenantContext::class)->forget();
    }

    private function account(string $provider, string $timezone): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: $provider,
        );

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => $provider, 'account_type' => 'ad_account', 'external_id' => "{$provider}-ad",
            'name' => ucfirst($provider), 'currency' => 'SAR', 'timezone' => $timezone, 'status' => 'active',
        ]);
    }
}
