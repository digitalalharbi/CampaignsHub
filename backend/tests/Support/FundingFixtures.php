<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Enums\SpendLimitScope;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\SpendLimit;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/** Small fixtures shared by the November budgets / spend-limits tests. Fake names only. */
trait FundingFixtures
{
    private Tenant $tenant;

    private User $finance;

    protected function bootFunding(array $permissions = ['funding.manage', 'funding.view', 'clients.view', 'clients.view_all', 'clients.create', 'projects.view.all', 'campaigns.view', 'campaigns.budget.change']): void
    {
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active',
            'account_type' => 'agency', 'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::query()->whereIn('key', $permissions)->pluck('key')->all());
        $this->finance = User::create(['name' => 'U '.uniqid(), 'email' => 'u-'.uniqid().'@a.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($this->finance, $this->tenant);
        $this->finance->assignRole($role);
        $this->holdingTenant((string) $this->tenant->id);
    }

    /** @param list<string> $permissions */
    private function userWith(array $permissions): User
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'slug' => 'v-'.uniqid()]);
        $role->givePermissionTo(...Permission::query()->whereIn('key', $permissions)->pluck('key')->all());
        $user = User::create(['name' => 'V '.uniqid(), 'email' => 'v-'.uniqid().'@a.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return $user;
    }

    /** An owner of the tenant with every permission, for the project-scoped endpoints. */
    private function owner(): User
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'tenant-owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@a.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return $user;
    }

    private function newProject(string $name = 'Launch', string $client = 'Club'): Project
    {
        $c = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => $client, 'slug' => Str::slug($client).'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);

        return Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $c->id, 'name' => $name, 'status' => 'active']);
    }

    private function day(int $ago): string
    {
        return Carbon::today('Asia/Riyadh')->subDays($ago)->toDateString();
    }

    private function uuid(string $seed): string
    {
        return Uuid::uuid5('6ba7b810-9dad-11d1-80b4-00c04fd430c8', $seed)->toString();
    }

    private function spendRow(Project $project, string $account, int $ago, float $amount, string $currency = 'SAR', string $provider = 'snapchat'): void
    {
        DailyMetric::query()->updateOrCreate(
            ['project_id' => $project->id, 'external_account_id' => $account, 'external_campaign_id' => $this->uuid('c1'),
                'metric_key' => 'spend', 'metric_date' => $this->day($ago), 'attribution_window' => 'default'],
            ['provider' => $provider, 'value' => $amount, 'original_amount' => $amount, 'original_currency' => $currency, 'project_currency' => 'SAR'],
        );
    }

    private function addBudget(Project $project, float $amount, int $ago = 5): void
    {
        $this->actingAs($this->finance, 'sanctum')->postJson('/api/v1/funding/payments', [
            'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id, 'amount' => $amount,
            'paid_on' => $this->day($ago), 'reference' => 'TRX-1', 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();
    }

    /** @var array<string, ExternalAccount> */
    private array $accounts = [];

    /** @var array<string, ExternalCampaign> */
    private array $campaigns = [];

    /** An ad account of the project (with its connection) for a provider; reused across a test's rows. */
    private function account(Project $project, string $provider): ExternalAccount
    {
        $key = $project->id.$provider;
        if (isset($this->accounts[$key])) {
            return $this->accounts[$key];
        }
        $credential = new IntegrationCredential(['provider' => $provider, 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('t');
        $credential->save();
        $connection = ProviderConnection::create(['credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => $provider, 'scope' => 'project_only', 'status' => 'connected']);
        $account = new ExternalAccount;
        $account->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => $provider, 'account_type' => 'ad_account', 'external_id' => 'act-'.$provider, 'name' => $provider,
            'status' => 'active', 'currency' => 'SAR'])->save();

        return $this->accounts[$key] = $account;
    }

    private function campaignOf(Project $project, string $provider): ExternalCampaign
    {
        $key = $project->id.$provider;
        if (isset($this->campaigns[$key])) {
            return $this->campaigns[$key];
        }
        $campaign = new ExternalCampaign;
        $campaign->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id,
            'external_account_id' => $this->account($project, $provider)->id, 'provider' => $provider, 'external_id' => $provider.'-cmp',
            'name' => $provider.' campaign', 'status' => 'active'])->save();

        return $this->campaigns[$key] = $campaign;
    }

    /** A day of project spend the governor can read: the figure is in $projectCurrency, the platform's own in $original. */
    private function limitSpend(Project $project, int $ago, float $original, string $originalCurrency = 'SAR', string $projectCurrency = 'SAR', string $provider = 'meta', ?float $value = null): void
    {
        DailyMetric::withoutGlobalScopes()->create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id,
            'external_account_id' => $this->account($project, $provider)->id, 'external_campaign_id' => $this->campaignOf($project, $provider)->id,
            'provider' => $provider, 'metric_key' => 'spend', 'metric_date' => $this->day($ago), 'value' => $value ?? $original,
            'original_amount' => $original, 'original_currency' => $originalCurrency, 'project_currency' => $projectCurrency, 'exchange_rate' => 1]);
    }

    private function makeLimit(Project $project, float $amount, string $currency = 'SAR', int $fromAgo = 10, int $toAhead = 20, ?array $thresholds = null, SpendLimitScope $scope = SpendLimitScope::Project, ?string $scopeId = null): SpendLimit
    {
        return SpendLimit::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'scope' => $scope->value, 'scope_id' => $scopeId,
            'amount' => $amount, 'currency' => $currency, 'starts_on' => $this->day($fromAgo), 'ends_on' => $this->day(-$toAhead),
            'thresholds' => $thresholds, 'active' => true]);
    }

    /** Select an account for a project — the deny-by-default rule every figure follows. */
    private function bind(Project $project, ExternalAccount $account): void
    {
        $exists = DB::table('project_integration_bindings')->where('project_id', $project->id)->where('external_account_id', $account->id)->exists();
        if ($exists) {
            DB::table('project_integration_bindings')->where('project_id', $project->id)->where('external_account_id', $account->id)->update(['is_active' => true]);
        }
    }
}
