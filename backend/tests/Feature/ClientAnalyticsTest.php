<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/** Client analytics: client-only isolation, currency-mode guardrails, objective-gated ROAS, permission. */
final class ClientAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $noAnalytics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ownerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $ownerRole->givePermissionTo(...Permission::pluck('key')->all());
        $limited = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'NoA', 'slug' => 'noa']);
        $limited->givePermissionTo('clients.view', 'clients.view_all');

        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($ownerRole);
        $this->noAnalytics = User::create(['name' => 'NoA', 'email' => 'na@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->noAnalytics, $this->tenant);
        $this->noAnalytics->assignRole($limited);
    }

    private function client(string $name): ClientWorkspace
    {
        return ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => Str::slug($name.'-'.uniqid()),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
    }

    private function project(ClientWorkspace $c, string $name): Project
    {
        return Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $c->id, 'name' => $name, 'status' => 'active']);
    }

    private function uid(string $label): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, "cat:{$label}");
    }

    /** Insert a daily_metrics row directly (deterministic; avoids the ingestion pipeline). */
    private function metric(string $projectId, string $key, float $value, string $date, string $currency = 'SAR', string $acct = 'a1'): void
    {
        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $projectId,
            'external_account_id' => $this->uid($acct),
            'external_campaign_id' => $this->uid($acct.'-camp'),
            'provider' => 'meta',
            'metric_key' => $key,
            'metric_date' => $date,
            'value' => $value,
            'project_currency' => $currency,
            'attribution_window' => 'default',
            'source_type' => 'api',
            'data_freshness_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    public function test_analytics_are_isolated_to_the_client_projects(): void
    {
        $a = $this->client('Client A');
        $pa = $this->project($a, 'A-proj');
        UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $a->id, 'project_id' => $pa->id, 'name' => 'A camp', 'objective' => 'sales', 'status' => 'active']);
        $this->metric($pa->id, 'spend', 500, '2026-07-20');
        $this->metric($pa->id, 'revenue', 2000, '2026-07-20');

        $b = $this->client('Client B');
        $pb = $this->project($b, 'B-proj');
        $this->metric($pb->id, 'spend', 9999, '2026-07-20', 'SAR', 'b1');

        $res = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/app/clients/{$a->id}/analytics?from=2026-07-01&to=2026-07-31")->assertOk();

        $res->assertJsonPath('data.currency_mode', 'single')
            ->assertJsonPath('data.currency', 'SAR')
            ->assertJsonPath('data.totals.spend', 500)       // only A's spend, never B's 9999
            ->assertJsonPath('data.roas_is_primary', true);  // sales objective dominates
        $this->assertStringNotContainsString('9999', $res->getContent());
        $this->assertEquals(4.0, $res->json('data.totals.roas')); // 2000/500 via shared aggregator
    }

    public function test_mixed_currency_suppresses_blended_money(): void
    {
        $a = $this->client('Mixed');
        $p1 = $this->project($a, 'P1');
        $p2 = $this->project($a, 'P2');
        $this->metric($p1->id, 'spend', 100, '2026-07-20', 'SAR', 'p1');
        $this->metric($p2->id, 'spend', 100, '2026-07-20', 'USD', 'p2');

        $res = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/app/clients/{$a->id}/analytics?from=2026-07-01&to=2026-07-31")->assertOk();

        $res->assertJsonPath('data.currency_mode', 'mixed')
            ->assertJsonPath('data.money_blended', false)
            ->assertJsonPath('data.totals', null)         // no blended money total across currencies
            ->assertJsonCount(2, 'data.projects');        // per-project money instead
    }

    public function test_analytics_requires_permission(): void
    {
        $a = $this->client('Perm');
        $this->actingAs($this->noAnalytics, 'sanctum')
            ->getJson("/api/v1/app/clients/{$a->id}/analytics")->assertForbidden();
    }

    public function test_cross_tenant_client_analytics_is_404(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        app(TenantContext::class)->forget();
        $foreign = ClientWorkspace::create(['tenant_id' => $other->id, 'name' => 'F', 'slug' => 'f-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/app/clients/{$foreign->id}/analytics")->assertNotFound();
    }
}
