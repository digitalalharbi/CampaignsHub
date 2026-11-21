<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CAMPAIGN-INTELLIGENCE-HUB — «efficient summaries, no N+1».
 *
 * The campaigns landing reads two endpoints: the campaign list and the per-campaign figures. Each must
 * answer a project of fifteen campaigns with the same number of queries as a project of three; a count
 * that grows with the list is a query per row, which is what turns a large client's page into a timeout.
 */
/**
 * CAMPAIGN-INTELLIGENCE-HUB — the campaigns landing and Analytics read ONE truth for one window.
 *
 * The landing lists each campaign's figures (`metrics/campaigns`); Analytics' strip totals the project (`metrics/summary`).
 * For the same window the rows must add up to the strip exactly — two platforms, campaigns with different figures, and rows
 * outside the window that both must leave out. A landing that disagreed with Analytics would be two products.
 */
final class CampaignsLandingAnalyticsParityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'o', 'email' => 'p-'.uniqid().'@p.test', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
    }

    public function test_the_landing_rows_add_up_to_the_analytics_strip_for_the_same_window(): void
    {
        $this->holdingTenant((string) $this->tenant->id);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'Parity', 'status' => 'active']);

        // [provider, campaign, per-day figures]
        $plan = [
            ['meta', 'Spring', ['spend' => 120.5, 'impressions' => 8000, 'clicks' => 64, 'conversions' => 4]],
            ['meta', 'Summer', ['spend' => 33.25, 'impressions' => 2100, 'clicks' => 9, 'conversions' => 0]],
            ['tiktok', 'Launch', ['spend' => 75, 'impressions' => 15000, 'clicks' => 120, 'conversions' => 7]],
        ];
        $accounts = [];
        foreach ($plan as [$provider, $name, $figures]) {
            if (! isset($accounts[$provider])) {
                $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: $provider, tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDay()), connectionName: $provider.uniqid());
                $accounts[$provider] = ExternalAccount::create(['tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => $provider,
                    'account_type' => 'ad_account', 'external_id' => 'act_'.uniqid(), 'name' => $provider, 'status' => 'active', 'currency' => 'SAR']);
                ProjectIntegrationBinding::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $accounts[$provider]->id,
                    'provider' => $provider, 'purpose' => 'ads', 'is_active' => true]);
            }
            $unified = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'name' => $name, 'status' => 'active', 'objective' => 'sales']);
            $external = ExternalCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $accounts[$provider]->id,
                'unified_campaign_id' => $unified->id, 'provider' => $provider, 'external_id' => 'c'.uniqid(), 'name' => $name, 'status' => 'active', 'currency' => 'SAR']);
            // Two days inside the window and one well before it, which both reads must leave out.
            foreach ([1, 3, 40] as $daysAgo) {
                foreach ($figures as $key => $value) {
                    DailyMetric::withoutGlobalScopes()->create([
                        'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $accounts[$provider]->id,
                        'external_campaign_id' => $external->id, 'unified_campaign_id' => $unified->id, 'provider' => $provider, 'metric_key' => $key,
                        'metric_date' => Carbon::now()->subDays($daysAgo)->toDateString(), 'value' => $value,
                    ]);
                }
            }
        }
        app(TenantContext::class)->forget();

        $window = '?from='.Carbon::now()->subDays(13)->toDateString().'&to='.Carbon::now()->toDateString();
        $rows = collect($this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$project->id}/metrics/campaigns{$window}")->assertOk()->json('data'));
        $strip = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$project->id}/metrics/summary{$window}")->assertOk()->json('data.current');

        $this->assertCount(3, $rows, 'every campaign with spend in the window is listed');
        foreach (['spend', 'impressions', 'clicks', 'conversions'] as $metric) {
            $this->assertEqualsWithDelta((float) $strip[$metric], (float) $rows->sum($metric), 0.001, "{$metric}: the landing rows and the Analytics strip disagree");
        }
        // The window is the same one: two in-window days per campaign, the 40-days-ago row in neither.
        $this->assertEqualsWithDelta(2 * (120.5 + 33.25 + 75), (float) $strip['spend'], 0.001);
        // A ratio on the strip is the ratio of the totals, not an average of the rows' ratios.
        $this->assertEqualsWithDelta(round((float) $strip['spend'] / (float) $strip['clicks'], 2), (float) $strip['cpc'], 0.01);
    }
}
