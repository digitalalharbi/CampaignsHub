<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
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
}
