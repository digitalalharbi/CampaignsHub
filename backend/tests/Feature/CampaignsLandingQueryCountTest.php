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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CAMPAIGN-INTELLIGENCE-HUB — «efficient summaries, no N+1».
 *
 * The campaigns landing reads two endpoints: the campaign list and the per-campaign figures. Each must
 * answer a project of fifteen campaigns with the same number of queries as a project of three; a count
 * that grows with the list is a query per row, which is what turns a large client's page into a timeout.
 */
final class CampaignsLandingQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Q', 'slug' => 'q-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'o', 'email' => 'q-'.uniqid().'@q.test', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
    }

    private function projectWith(int $campaigns): Project
    {
        $this->holdingTenant((string) $this->tenant->id);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P'.$campaigns, 'status' => 'active']);
        $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: 'meta', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDay()), connectionName: 'meta'.uniqid());
        $account = ExternalAccount::create(['tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'meta', 'account_type' => 'ad_account', 'external_id' => 'act_'.uniqid(), 'name' => 'A', 'status' => 'active', 'currency' => 'SAR']);
        ProjectIntegrationBinding::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id, 'provider' => 'meta', 'purpose' => 'ads', 'is_active' => true]);

        for ($i = 0; $i < $campaigns; $i++) {
            $unified = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'name' => "Campaign {$i}", 'status' => 'active', 'objective' => 'sales']);
            $external = ExternalCampaign::create([
                'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id, 'unified_campaign_id' => $unified->id,
                'provider' => 'meta', 'external_id' => 'c'.uniqid(), 'name' => "Campaign {$i}", 'status' => 'active', 'currency' => 'SAR',
            ]);
            foreach (['spend' => 100, 'impressions' => 5000, 'clicks' => 50, 'conversions' => 3] as $key => $value) {
                foreach ([1, 2] as $daysAgo) {
                    DailyMetric::withoutGlobalScopes()->create([
                        'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id,
                        'external_campaign_id' => $external->id, 'unified_campaign_id' => $unified->id, 'provider' => 'meta', 'metric_key' => $key,
                        'metric_date' => Carbon::now()->subDays($daysAgo)->toDateString(), 'value' => $value,
                    ]);
                }
            }
        }
        app(TenantContext::class)->forget();

        return $project;
    }

    private function queriesFor(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->owner, 'sanctum')->getJson($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_the_campaign_list_and_its_figures_do_not_query_per_campaign(): void
    {
        $small = $this->projectWith(3);
        $large = $this->projectWith(15);
        $window = '?from='.Carbon::now()->subDays(7)->toDateString().'&to='.Carbon::now()->toDateString();

        foreach (['campaigns', 'metrics/campaigns'] as $path) {
            // Warm both: the first read of a window also queues its reach request, once per account.
            $this->queriesFor("/api/v1/projects/{$small->id}/{$path}{$window}");
            $this->queriesFor("/api/v1/projects/{$large->id}/{$path}{$window}");
            $few = $this->queriesFor("/api/v1/projects/{$small->id}/{$path}{$window}");
            $many = $this->queriesFor("/api/v1/projects/{$large->id}/{$path}{$window}");

            $this->assertSame($few, $many, "{$path}: {$few} queries for 3 campaigns but {$many} for 15 — a query per campaign");
        }
    }
}
