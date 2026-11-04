<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Deny by default, held through the real surfaces — Tenant -> Workspace -> Project -> Authorised Account.
 *
 * ONE project, THREE accounts, every grain filed under the project in the legacy shape:
 *
 *   (a) actively bound            -> counted, listed, synced
 *   (b) deselected (is_active=0)  -> stored and inventoried, not read, not synced
 *   (c) no binding record at all  -> stored and inventoried, not read, not synced
 *
 * Distinct spends (100 / 10 / 1) so a surface that counted the wrong account cannot land on the right sum.
 * `BoundAccountVisibility::UNBOUND_ROWS_VISIBLE` is false; this is the proof that it is the whole story.
 */
final class UnboundAndDeselectedAccountsAreNotReadTest extends TestCase
{
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    private const FROM = '2026-08-01';

    private const TO = '2026-08-31';

    private Tenant $tenant;

    private User $operator;

    private ClientWorkspace $workspace;

    private Project $project;

    private ProviderConnection $connection;

    /** @var array<string, ExternalAccount> keyed bound|deselected|unbound */
    private array $account = [];

    /** @var array<string, array{campaign: string, unified: string, ad: string, creative: string, name: string}> */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'UTC'));
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->tenant, Portal::Agency);
        $this->operator->assignRole($role);

        $this->workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'P', 'status' => 'active',
        ]);

        $credential = new IntegrationCredential([
            'tenant_id' => $this->tenant->id, 'provider' => 'snapchat', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();
        $this->connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => 'snapchat',
            'connection_name' => 'snap', 'scope' => 'project_only', 'status' => 'connected',
        ]);

        foreach (['bound' => 100.0, 'deselected' => 10.0, 'unbound' => 1.0] as $kind => $spend) {
            $this->account[$kind] = ExternalAccount::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'provider_connection_id' => $this->connection->id, 'provider' => 'snapchat',
                'account_type' => 'ad_account', 'external_id' => 'act-'.$kind, 'name' => $kind, 'status' => 'active', 'discovered_at' => Carbon::now(),
            ]);
        }
        $this->bind($this->account['bound'], true);
        $this->bind($this->account['deselected'], false);
        // `unbound`: no binding record at all.

        foreach (['bound' => 100.0, 'deselected' => 10.0, 'unbound' => 1.0] as $kind => $spend) {
            $this->seed3($kind, $spend);
        }

        app(TenantContext::class)->forget();
        app(ProjectContext::class)->forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_aggregator_counts_only_the_bound_account(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(ProjectContext::class)->setProjectId($this->project->id);
        $engine = app(MetricsAggregator::class);

        $this->assertEqualsWithDelta(200.0, (float) ($engine->totals(Carbon::parse(self::FROM), Carbon::parse(self::TO))['spend'] ?? 0), 0.001);

        $series = $engine->timeseries(Carbon::parse(self::FROM), Carbon::parse(self::TO));
        $this->assertEqualsWithDelta(200.0, $this->sumSpend($series), 0.001, 'two days of the bound account only (2 x 100)');
    }

    public function test_the_project_endpoints_list_and_count_only_the_bound_account(): void
    {
        $summary = $this->asOperator('metrics/summary?from='.self::FROM.'&to='.self::TO);
        $this->assertEqualsWithDelta(200.0, (float) ($summary['current']['spend'] ?? -1), 0.001);

        $platforms = $this->asOperator('metrics/platforms?from='.self::FROM.'&to='.self::TO);
        $this->assertEqualsWithDelta(200.0, (float) collect($platforms)->sum(fn (array $r): float => (float) ($r['spend'] ?? 0)), 0.001);

        $campaigns = $this->asOperator('metrics/campaigns?from='.self::FROM.'&to='.self::TO);
        $this->assertEqualsWithDelta(200.0, (float) collect($campaigns)->sum(fn (array $r): float => (float) ($r['spend'] ?? 0)), 0.001);

        $timeseries = $this->asOperator('metrics/timeseries?from='.self::FROM.'&to='.self::TO);
        $this->assertEqualsWithDelta(200.0, $this->sumSpend($timeseries), 0.001);

        // What the report builder's pickers offer: the campaigns, ads and creatives of the bound account only.
        foreach (['campaigns' => 'unified', 'ads' => 'ad', 'creatives' => 'creative'] as $axis => $key) {
            $offered = array_column($this->asOperator('reports/scope/options?axis='.$axis)[$axis] ?? [], 'id');
            $this->assertContains($this->rows['bound'][$key], $offered, "{$axis}: the bound account's row is not offered");
            $this->assertNotContains($this->rows['deselected'][$key], $offered, "{$axis}: the deselected account's row is offered");
            $this->assertNotContains($this->rows['unbound'][$key], $offered, "{$axis}: the unbound account's row is offered");
        }
    }

    public function test_the_client_analytics_count_only_the_bound_account(): void
    {
        $data = $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/app/clients/{$this->workspace->id}/analytics?from=".self::FROM.'&to='.self::TO)
            ->assertOk()->json('data') ?? [];

        $this->assertEqualsWithDelta(200.0, (float) ($data['totals']['spend'] ?? -1), 0.001);
    }

    public function test_the_live_public_report_and_its_files_carry_only_the_bound_account(): void
    {
        $token = $this->liveLink();

        $page = $this->getJson("/api/v1/reports/shared/{$token}/live?from=".self::FROM.'&to='.self::TO)->assertOk()->json('data');

        $this->assertEqualsWithDelta(200.0, (float) ($page['totals']['spend'] ?? -1), 0.001);

        $this->assertSame(['snapchat'], array_values(array_unique(array_map(fn ($p) => (string) ($p['provider'] ?? $p['platform'] ?? ''), (array) $page['platforms']))), 'platforms');

        $campaignNames = array_map(fn ($r) => (string) ($r['name'] ?? ''), (array) $page['campaign_rows']);
        $this->assertContains('U '.$this->rows['bound']['name'], $campaignNames, 'the bound campaign is missing from campaign_rows');
        $this->assertNotContains('U '.$this->rows['deselected']['name'], $campaignNames);
        $this->assertNotContains('U '.$this->rows['unbound']['name'], $campaignNames);

        $roster = (string) json_encode($page['ads_roster']);
        $this->assertStringNotContainsString($this->rows['deselected']['name'], $roster);
        $this->assertStringNotContainsString($this->rows['unbound']['name'], $roster);
        $this->assertStringNotContainsString($this->rows['deselected']['ad'], $roster);
        $this->assertStringNotContainsString($this->rows['unbound']['ad'], $roster);

        // The file the same link hands out says the same thing.
        $csv = $this->get("/api/v1/reports/shared/{$token}/download/csv")->assertOk()->streamedContent();
        $this->assertMatchesRegularExpression('/^spend,200(\.0+)?$/m', $csv, 'the CSV does not carry the bound account\'s spend alone');
        $this->assertStringNotContainsString($this->rows['deselected']['name'], $csv);
        $this->assertStringNotContainsString($this->rows['unbound']['name'], $csv);

        $this->get("/api/v1/reports/shared/{$token}/download/xlsx")->assertOk();
    }

    public function test_the_sync_sweep_queues_only_the_bound_account(): void
    {
        Queue::fake();

        $this->artisan('integrations:sync')->assertSuccessful();

        Queue::assertPushed(SyncAccountMetricsJob::class, 1);
        Queue::assertPushed(SyncAccountMetricsJob::class, fn (SyncAccountMetricsJob $job) => str_contains($job->uniqueId(), (string) $this->account['bound']->id));
        Queue::assertNotPushed(SyncAccountMetricsJob::class, fn (SyncAccountMetricsJob $job) => str_contains($job->uniqueId(), (string) $this->account['deselected']->id)
            || str_contains($job->uniqueId(), (string) $this->account['unbound']->id));
    }

    public function test_the_inventory_still_counts_what_is_stored_but_not_read(): void
    {
        Artisan::call('integrations:scope-audit', ['--project' => $this->project->id]);
        $out = Artisan::output();

        $this->assertMatchesRegularExpression('/once_bound\s+'.preg_quote($this->account['deselected']->id, '/').'/', $out);
        $this->assertMatchesRegularExpression('/unbound\s+'.preg_quote($this->account['unbound']->id, '/').'/', $out);

        // Three campaigns: total 3, outside 2, once_bound 1, bound_elsewhere 0, unbound 1.
        $this->assertMatchesRegularExpression('/'.preg_quote($this->project->id, '/').'\s+external_campaigns\s+3\s+2\s+1\s+0\s+1\s+0/', $out);
        // Two metric days per account.
        $this->assertMatchesRegularExpression('/'.preg_quote($this->project->id, '/').'\s+daily_metrics\s+18\s+12\s+6\s+0\s+6\s+0/', $out);
        $this->assertMatchesRegularExpression('/'.preg_quote($this->project->id, '/').'\s+entity_daily_metrics\s+6\s+4\s+2\s+0\s+2\s+0/', $out);
        $this->assertMatchesRegularExpression('/'.preg_quote($this->project->id, '/').'\s+creative_daily_metrics\s+6\s+4\s+2\s+0\s+2\s+0/', $out);

        // Nothing was deleted by being hidden.
        $this->assertSame(6, DB::table('daily_metrics')->where('metric_key', 'spend')->count());
    }

    // ── helpers ────────────────────────────────────────────────────────────────────────────────────

    /** @param  array<int|string, mixed>  $series */
    private function sumSpend(array $series): float
    {
        $total = 0.0;
        foreach ($series as $point) {
            $total += (float) (((array) $point)['spend'] ?? 0);
        }

        return $total;
    }

    /** @return array<mixed> */
    private function asOperator(string $path): array
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->withHeader('X-Project-Id', $this->project->id)
            ->getJson("/api/v1/projects/{$this->project->id}/{$path}")
            ->assertOk()
            ->json('data') ?? [];
    }

    private function liveLink(): string
    {
        $report = Report::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'R', 'type' => 'monthly',
            'status' => 'completed', 'currency' => 'SAR', 'period_start' => self::FROM, 'period_end' => self::TO, 'data' => [],
        ]);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(ProjectContext::class)->setProjectId($this->project->id);
        $report->forceFill(['data' => app(ReportGenerator::class)->generate($report)])->save();
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        [, $raw] = app(ShareService::class)->create($report, [
            'mode' => 'live',
            'allow_download' => true,
            'settings' => ['campaign_breakdown' => true],
            'scope' => [
                'project_id' => $this->project->id,
                // The ids the builder's picker OFFERS — the picker is itself under the rule, so a link cannot be built to name the others.
                'campaign_ids' => array_column($this->asOperator('reports/scope/options?axis=campaigns')['campaigns'] ?? [], 'id'),
                'providers' => ['snapchat'],
                'earliest' => self::FROM,
                'latest' => self::TO,
            ],
        ], null);

        return $raw;
    }

    private function bind(ExternalAccount $account, bool $active): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'project_id' => $this->project->id,
            'external_account_id' => $account->id, 'provider' => 'snapchat', 'purpose' => 'advertising', 'is_active' => $active, 'sync_enabled' => true,
        ]);
    }

    /** Every grain for one account, filed under the project in the legacy shape. */
    private function seed3(string $kind, float $spend): void
    {
        $account = $this->account[$kind];
        $base = ['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'created_at' => now(), 'updated_at' => now()];
        $campaign = (string) Str::uuid();
        $adSet = (string) Str::uuid();
        $ad = (string) Str::uuid();
        $creative = (string) Str::uuid();
        $name = 'Zed'.strtoupper($kind).'Mark';

        $unified = UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'client_workspace_id' => $this->workspace->id,
            'name' => 'U '.$name, 'objective' => 'sales', 'status' => 'active', 'platforms' => ['snapchat'],
        ]);

        DB::table('external_campaigns')->insert($base + [
            'id' => $campaign, 'external_account_id' => $account->id, 'unified_campaign_id' => $unified->id, 'provider' => 'snapchat',
            'external_id' => 'cmp-'.$kind, 'name' => $name, 'status' => 'active', 'objective' => 'sales',
        ]);
        DB::table('external_ad_sets')->insert($base + [
            'id' => $adSet, 'external_campaign_id' => $campaign, 'unified_campaign_id' => $unified->id, 'provider' => 'snapchat', 'external_id' => 'sq-'.$kind, 'name' => $name.'Set',
        ]);
        DB::table('external_creatives')->insert($base + [
            'id' => $creative, 'external_campaign_id' => $campaign, 'campaign_id' => $unified->id, 'provider' => 'snapchat',
            'external_creative_id' => 'cr-'.$kind, 'name' => $name.'Creative', 'format' => 'image', 'asset_url' => 'https://cdn.example/'.$kind,
        ]);
        DB::table('external_ads')->insert($base + [
            'id' => $ad, 'external_campaign_id' => $campaign, 'external_ad_set_id' => $adSet, 'unified_campaign_id' => $unified->id, 'creative_id' => $creative,
            'provider' => 'snapchat', 'external_id' => 'ad-'.$kind, 'name' => $name.'Ad',
        ]);

        foreach (['2026-08-10', '2026-08-11'] as $date) {
            foreach (['spend' => $spend, 'impressions' => 1000, 'clicks' => 10] as $key => $value) {
                DB::table('daily_metrics')->insert($base + [
                    'id' => (string) Str::uuid(), 'external_account_id' => $account->id, 'external_campaign_id' => $campaign,
                    'unified_campaign_id' => $unified->id, 'provider' => 'snapchat', 'metric_key' => $key, 'metric_date' => $date,
                    'value' => $value, 'project_currency' => 'SAR', 'original_currency' => 'SAR', 'data_freshness_at' => now(),
                ]);
            }
            DB::table('entity_daily_metrics')->insert($base + [
                'id' => (string) Str::uuid(), 'external_account_id' => $account->id, 'external_campaign_id' => $campaign,
                'provider' => 'snapchat', 'entity_type' => 'ad', 'entity_id' => $ad, 'external_entity_id' => 'ad-'.$kind,
                'metric_date' => $date, 'spend' => $spend, 'impressions' => 1000,
            ]);
            DB::table('creative_daily_metrics')->insert($base + [
                'id' => (string) Str::uuid(), 'creative_id' => $creative, 'campaign_id' => $unified->id, 'metric_date' => $date,
                'spend' => $spend, 'impressions' => 1000, 'clicks' => 10,
            ]);
        }

        $this->rows[$kind] = ['campaign' => $campaign, 'unified' => (string) $unified->id, 'ad' => $ad, 'creative' => $creative, 'name' => $name];
    }
}
