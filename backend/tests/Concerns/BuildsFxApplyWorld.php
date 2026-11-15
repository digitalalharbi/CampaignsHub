<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Fx\RateLedger;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\ReportingCurrency;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * FX-APPLY-001 (builder G) fixtures: one workspace, one client, one project, ad accounts in EUR / USD / SAR / GBP bound to it,
 * a campaign whose budget is 253 EUR and whose spend is 253 EUR on 2026-11-09 with EUR→USD 1.12 recorded (manual). Synthetic
 * names only; nothing talks to a platform. Money rows are written exactly as ingest writes them (`ReportingCurrency::normalise`).
 */
trait BuildsFxApplyWorld
{
    protected Tenant $tenant;

    protected User $owner;

    protected ClientWorkspace $client;

    protected Project $project;

    protected function buildFxApplyWorld(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 12:00:00'));
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'FX Apply', 'slug' => 'fx-apply-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-fx']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@fx-apply.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency);
        $this->owner->assignRole($role);

        $this->client = ClientWorkspace::create(['name' => 'Client', 'slug' => 'client-fx', 'mode' => 'managed', 'default_currency' => 'SAR']);
        $this->project = Project::create(['client_workspace_id' => $this->client->id, 'name' => 'Project', 'status' => 'active']);
    }

    protected function rate(string $base, string $quote, float $rate, string $date, string $kind = CurrencyRate::MANUAL): void
    {
        app(RateLedger::class)->record($base, $quote, $rate, $date, $kind, by: $this->owner);
    }

    protected function adAccount(string $currency, string $provider = 'google'): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: $provider, tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDay()), connectionName: $provider.uniqid());
        $account = ExternalAccount::create(['tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => $provider, 'account_type' => 'ad_account',
            'external_id' => 'acc-'.Str::random(8), 'name' => 'Account '.$currency, 'status' => 'active', 'currency' => $currency]);
        ProjectIntegrationBinding::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id, 'provider' => $provider, 'purpose' => 'ads', 'is_active' => true]);

        return $account;
    }

    /** @return array{0: UnifiedCampaign, 1: ExternalCampaign} */
    protected function campaign(ExternalAccount $account, string $name, ?float $budget = null, ?float $dailyBudget = null): array
    {
        $unified = UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'client_workspace_id' => $this->client->id, 'name' => $name,
            'objective' => 'traffic', 'status' => 'active', 'total_budget' => $budget, 'budget_currency' => $account->currency, 'platforms' => [$account->provider],
        ]);
        $external = ExternalCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id, 'provider' => $account->provider,
            'external_id' => (string) random_int(100000, 999999), 'name' => $name, 'status' => 'active', 'currency' => null, 'unified_campaign_id' => $unified->id,
            'daily_budget' => $dailyBudget,
        ]);

        return [$unified, $external];
    }

    protected function adSet(ExternalCampaign $campaign, float $dailyBudget): ExternalAdSet
    {
        return ExternalAdSet::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_campaign_id' => $campaign->id, 'unified_campaign_id' => $campaign->unified_campaign_id,
            'provider' => $campaign->provider, 'external_id' => (string) random_int(100000, 999999), 'name' => 'Ad group', 'status' => 'active',
            'daily_budget' => $dailyBudget, 'currency' => null, 'source_type' => 'live',
        ]);
    }

    /** A spend (or other money) row as ingest writes it: converted by the engine's rate rule, or withheld. */
    protected function moneyRow(ExternalAccount $account, ExternalCampaign $campaign, float $amount, string $date, string $key = 'spend'): DailyMetric
    {
        $money = app(ReportingCurrency::class)->normalise($amount, $account->currency, ReportingCurrency::DEFAULT, Carbon::parse($date));

        return DailyMetric::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id,
            'external_campaign_id' => $campaign->id, 'unified_campaign_id' => $campaign->unified_campaign_id, 'provider' => $account->provider,
            'metric_key' => $key, 'metric_date' => $date, ...$money,
        ]);
    }

    protected function countRow(ExternalAccount $account, ExternalCampaign $campaign, string $key, float $n, string $date): void
    {
        DailyMetric::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id,
            'external_campaign_id' => $campaign->id, 'unified_campaign_id' => $campaign->unified_campaign_id, 'provider' => $account->provider,
            'metric_key' => $key, 'metric_date' => $date, 'value' => $n,
        ]);
    }

    /** @return array<string, string> the query of the window every walk reads */
    protected function window(): array
    {
        return ['from' => '2026-11-01', 'to' => '2026-11-15'];
    }
}
