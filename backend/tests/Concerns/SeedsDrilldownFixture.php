<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REPORT-DRILLDOWN-001 — the drill-down fixture: meta and tiktok under their own accounts, plus a
 * third meta account whose binding to the project is DESELECTED (its 9,000 must never appear).
 */
trait SeedsDrilldownFixture
{
    private Tenant $tenant;

    private Project $project;

    private Report $report;

    private User $operator;

    /** @var list<string> */
    private array $campaigns = [];

    private function seedDrilldownFixture(): void
    {
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'drilldown-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId($this->project->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->getKey(),
            provider: 'meta',
            tokens: new OAuthTokens('AT', 'RT', now()->addDays(30)),
            connectionName: 'meta',
        );
        $account = fn (string $provider, string $ext): string => (string) ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'provider_connection_id' => $connection->getKey(),
            'provider' => $provider, 'account_type' => 'ad_account',
            'external_id' => $ext, 'name' => $ext, 'status' => 'active', 'discovered_at' => now(),
        ])->getKey();

        $deselected = $account('meta', 'act-elsewhere');
        $fixture = [
            // label, provider, account, [spend, impressions, clicks, conversions, revenue]
            ['Meta Summer', 'meta', $account('meta', 'act-meta'), [300, 20000, 400, 12, 1500]],
            ['TikTok Launch', 'tiktok', $account('tiktok', 'act-tiktok'), [100, 30000, 300, 4, 200]],
            ['Meta Elsewhere', 'meta', $deselected, [9000, 90000, 900, 90, 90000]],
        ];

        foreach ($fixture as [$label, $provider, $accountId, [$spend, $impressions, $clicks, $conversions, $revenue]]) {
            $campaign = UnifiedCampaign::create(['project_id' => $this->project->id, 'name' => $label, 'status' => 'active', 'objective' => 'sales']);
            $this->campaigns[] = $campaign->id;
            $external = ExternalCampaign::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
                'external_account_id' => $accountId, 'unified_campaign_id' => $campaign->id,
                'provider' => $provider, 'external_id' => 'cmp-'.Str::random(6), 'name' => $label, 'status' => 'active',
            ]);
            $creative = ExternalCreative::create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'campaign_id' => $campaign->id,
                'external_campaign_id' => $external->getKey(),
                'provider' => $provider, 'external_creative_id' => 'cr-'.Str::random(8),
                'name' => "{$label} creative", 'format' => 'image', 'status' => 'active',
            ]);
            foreach (['2026-07-10', '2026-07-11'] as $date) {
                foreach (['spend' => $spend, 'impressions' => $impressions, 'clicks' => $clicks, 'conversions' => $conversions, 'revenue' => $revenue] as $key => $value) {
                    DailyMetric::create([
                        'id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'external_account_id' => $accountId,
                        'external_campaign_id' => $external->getKey(), 'unified_campaign_id' => $campaign->id,
                        'provider' => $provider, 'metric_key' => $key, 'metric_date' => $date, 'value' => $value / 2,
                    ]);
                }
                DB::table('creative_daily_metrics')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                    'creative_id' => $creative->id, 'metric_date' => $date, 'spend' => $spend / 2,
                    'impressions' => $impressions / 2, 'clicks' => $clicks / 2, 'conversions' => $conversions / 2, 'revenue' => $revenue / 2,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        // The third account was bound here once and deselected: its rows are someone else's now.
        DB::table('project_integration_bindings')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $deselected, 'provider' => 'meta', 'purpose' => 'ads', 'is_active' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->report = Report::create([
            'project_id' => $this->project->id, 'name' => 'R', 'type' => 'executive', 'status' => 'completed',
            'currency' => 'SAR', 'campaign_objective' => 'sales', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'data' => [],
        ]);

        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
    }
}
