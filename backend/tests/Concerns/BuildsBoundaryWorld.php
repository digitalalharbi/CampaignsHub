<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * INTEG-UNIFIED-BOUNDARIES-001 — the smallest world the boundary tests share: tenants, workspaces, projects,
 * authorisations (connections), the resources they reach (external accounts) and the bindings that select them.
 *
 * Every helper writes through the product's own rows (`TokenVault::open`, the real binding table), never a
 * shortcut, so a boundary test exercises the same shapes the sweeps, jobs and readers see in production.
 * A test using this opts out of the seeded-account auto-binder (`$selectsSeededAccounts = false`) because
 * selecting an account is the thing under test.
 */
trait BuildsBoundaryWorld
{
    protected function bwTenant(string $name = 'T'): Tenant
    {
        $tenant = Tenant::create(['name' => $name, 'slug' => strtolower($name).'-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        return $tenant;
    }

    protected function bwWorkspace(Tenant $tenant, string $name = 'W'): ClientWorkspace
    {
        return ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => $name, 'slug' => strtolower($name).'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active', 'default_currency' => 'USD',
        ]);
    }

    protected function bwProject(Tenant $tenant, ClientWorkspace $workspace, string $name = 'P'): Project
    {
        return Project::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $workspace->id, 'name' => $name, 'status' => 'active',
        ]);
    }

    /** Fill the platform's configured secrets so its connector is not «awaiting credentials». */
    protected function bwConfigure(string $platform, string $configFile = 'ad_platforms'): void
    {
        foreach (PlatformCredentials::for($platform)->requires() as $key) {
            config()->set("{$configFile}.platforms.{$platform}.{$key}", "test-{$key}");
        }
    }

    /** One authorisation. Two workspaces give one tenant two connections of one provider. */
    protected function bwConnection(Tenant $tenant, string $provider, ?ClientWorkspace $workspace = null, string $accessToken = 'AT', ?Carbon $expiresAt = null): ProviderConnection
    {
        return app(TokenVault::class)->open(
            tenantId: $tenant->id,
            provider: $provider,
            tokens: new OAuthTokens($accessToken, 'RT', $expiresAt ?? Carbon::now()->addDays(30), raw: $provider === 'salla' || $provider === 'zid' ? ['authorization' => 'MANAGER'] : []),
            connectionName: $provider.'-'.($workspace?->name ?? 'tenant'),
            clientWorkspaceId: $workspace?->id,
        );
    }

    /** @param array<string,mixed> $extra */
    protected function bwAccount(ProviderConnection $connection, string $externalId, string $type = 'ad_account', array $extra = []): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $connection->tenant_id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => $connection->provider,
            'account_type' => $type,
            'external_id' => $externalId,
            'name' => $externalId.' name',
            'status' => 'active',
            'currency' => 'USD',
            'timezone' => 'Asia/Riyadh',
            'discovered_at' => Carbon::now(),
        ] + $extra);
    }

    protected function bwBind(ExternalAccount $account, Project $project, string $purpose = 'advertising', bool $active = true): ProjectIntegrationBinding
    {
        return ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $account->tenant_id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => $account->provider, 'purpose' => $purpose,
            'is_active' => $active, 'sync_enabled' => true, 'campaign_management_enabled' => $purpose === 'advertising',
        ]);
    }

    /** An agency owner holding every permission of the tenant. */
    protected function bwOperator(Tenant $tenant): User
    {
        $this->seed(PermissionSeeder::class);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($user, $tenant, Portal::Agency);
        $user->assignRole($role);

        return $user;
    }

    /**
     * Every grain for one campaign of one account, filed under one project, with two measured days (2026-08-10 / 11).
     *
     * @return array{campaign: string, unified: string, ad: string, creative: string, name: string}
     */
    protected function bwFigures(ExternalAccount $account, Project $project, string $campaignExternalId, float $spend, string $name): array
    {
        $provider = (string) $account->provider;
        $base = ['tenant_id' => $account->tenant_id, 'project_id' => $project->id, 'created_at' => now(), 'updated_at' => now()];
        [$campaign, $adSet, $ad, $creative] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];

        $unified = \App\Domains\Campaigns\Models\UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $account->tenant_id, 'project_id' => $project->id, 'client_workspace_id' => $project->client_workspace_id,
            'name' => 'U '.$name, 'objective' => 'sales', 'status' => 'active', 'platforms' => [$provider],
        ]);

        DB::table('external_campaigns')->insert($base + [
            'id' => $campaign, 'external_account_id' => $account->id, 'unified_campaign_id' => $unified->id, 'provider' => $provider,
            'external_id' => $campaignExternalId, 'name' => $name, 'status' => 'active', 'objective' => 'sales',
        ]);
        DB::table('external_ad_sets')->insert($base + [
            'id' => $adSet, 'external_campaign_id' => $campaign, 'unified_campaign_id' => $unified->id, 'provider' => $provider, 'external_id' => 'sq-'.$name, 'name' => $name.'Set',
        ]);
        DB::table('external_creatives')->insert($base + [
            'id' => $creative, 'external_campaign_id' => $campaign, 'campaign_id' => $unified->id, 'provider' => $provider,
            'external_creative_id' => 'cr-'.$name, 'name' => $name.'Creative', 'format' => 'image', 'asset_url' => 'https://cdn.example/'.$name,
        ]);
        DB::table('external_ads')->insert($base + [
            'id' => $ad, 'external_campaign_id' => $campaign, 'external_ad_set_id' => $adSet, 'unified_campaign_id' => $unified->id, 'creative_id' => $creative,
            'provider' => $provider, 'external_id' => 'ad-'.$name, 'name' => $name.'Ad',
        ]);

        foreach (['2026-08-10', '2026-08-11'] as $date) {
            foreach (['spend' => $spend, 'impressions' => 1000, 'clicks' => 10] as $key => $value) {
                DB::table('daily_metrics')->insert($base + [
                    'id' => (string) Str::uuid(), 'external_account_id' => $account->id, 'external_campaign_id' => $campaign,
                    'unified_campaign_id' => $unified->id, 'provider' => $provider, 'metric_key' => $key, 'metric_date' => $date,
                    'value' => $value, 'project_currency' => 'SAR', 'original_currency' => 'SAR', 'data_freshness_at' => now(),
                ]);
            }
            DB::table('entity_daily_metrics')->insert($base + [
                'id' => (string) Str::uuid(), 'external_account_id' => $account->id, 'external_campaign_id' => $campaign,
                'provider' => $provider, 'entity_type' => 'ad', 'entity_id' => $ad, 'external_entity_id' => 'ad-'.$name,
                'metric_date' => $date, 'spend' => $spend, 'impressions' => 1000,
            ]);
            DB::table('creative_daily_metrics')->insert($base + [
                'id' => (string) Str::uuid(), 'creative_id' => $creative, 'campaign_id' => $unified->id, 'metric_date' => $date,
                'spend' => $spend, 'impressions' => 1000, 'clicks' => 10,
            ]);
        }

        return ['campaign' => $campaign, 'unified' => (string) $unified->id, 'ad' => $ad, 'creative' => $creative, 'name' => $name];
    }

    /** A public live-report link over one project, scoped to the campaigns its picker offers. Returns the raw token. */
    protected function bwLiveLink(Project $project, User $operator, array $providers): string
    {
        $options = $this->bwAsOperator($operator, $project, 'reports/scope/options?axis=campaigns')['campaigns'] ?? [];

        $report = \App\Domains\Reports\Models\Report::withoutGlobalScopes()->create([
            'tenant_id' => $project->tenant_id, 'project_id' => $project->id, 'name' => 'R', 'type' => 'monthly',
            'status' => 'completed', 'currency' => 'SAR', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'data' => [],
        ]);
        app(TenantContext::class)->setTenantId((string) $project->tenant_id);
        app(\App\Domains\Projects\Context\ProjectContext::class)->setProjectId((string) $project->id);
        $report->forceFill(['data' => app(\App\Domains\Reports\Services\ReportGenerator::class)->generate($report)])->save();
        app(\App\Domains\Projects\Context\ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        [, $raw] = app(\App\Domains\Reports\Services\ShareService::class)->create($report, [
            'mode' => 'live',
            'allow_download' => true,
            'settings' => ['campaign_breakdown' => true],
            'scope' => [
                'project_id' => $project->id,
                'campaign_ids' => array_column($options, 'id'),
                'providers' => $providers,
                'earliest' => '2026-08-01',
                'latest' => '2026-08-31',
            ],
        ], null);

        return $raw;
    }

    /** One project's own read, as its operator, through the real endpoint. @return array<mixed> */
    protected function bwAsOperator(User $operator, Project $project, string $path): array
    {
        $data = $this->actingAs($operator, 'sanctum')
            ->withHeader('X-Project-Id', $project->id)
            ->getJson("/api/v1/projects/{$project->id}/{$path}")
            ->assertOk()
            ->json('data') ?? [];

        // A header set on the test instance sticks to every later request, and would silently scope the next, workspace-level, read.
        $this->flushHeaders();

        return $data;
    }
}
