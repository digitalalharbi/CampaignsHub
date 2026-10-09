<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Actions\ImportStoreData;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * ANALYTICS-DIAGNOSTIC-INTELLIGENCE-001 — the refund arm.
 *
 * The diagnostic chain the row names ends «… → purchases → AOV → refunds», and the layer reads the
 * metrics summary's totals. `refunded_total` lives on `CommerceOrder`, not in `daily_metrics`, so
 * the chain's last link was never in the input: a store whose revenue was being handed straight
 * back could not be told apart from one keeping it, and the diagnosis said «value is fine».
 *
 * Three rules, each a case below:
 *
 *   1. Refunds reach the summary as a figure the diagnostic can read, with the window the store's
 *      own timezone defines, and converted like every other money figure (COMMERCE-FX-001).
 *   2. A project with no store in scope reports refunds as ABSENT — null and `reported: false` —
 *      never as zero. «Never convert unavailable into zero.»
 *   3. Refunds have no campaign, objective or platform on them. When the request narrows by one of
 *      those axes, the figure says it did not apply them, in the same `filter_scope` vocabulary the
 *      objective panels use, rather than answering a wider question under a narrower chip.
 */
final class DiagnosticRefundArmTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ra-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);

        $workspace = ClientWorkspace::create(['name' => 'Client', 'slug' => 'rc-'.uniqid(), 'mode' => 'managed', 'default_currency' => 'USD']);
        $this->project = Project::create(['client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active']);

        /* Spend in the window, so the summary is a summary of something. */
        app(UpsertDailyMetrics::class)->handle([
            new NormalizedMetric(
                tenantId: (string) $this->tenant->id, projectId: (string) $this->project->id,
                externalAccountId: (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, 'ra:acc'),
                externalCampaignId: (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, 'ra:camp'),
                provider: 'meta', metricKey: 'spend', metricDate: Carbon::parse('2026-06-10'), value: 100.0,
            ),
        ]);
    }

    private function summary(string $query = ''): array
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/metrics/summary?from=2026-06-01&to=2026-06-30{$query}")
            ->assertOk()
            ->json('data');
    }

    private function store(string $label): ExternalAccount
    {
        $credential = new IntegrationCredential(['provider' => 'salla', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('t');
        $credential->save();
        $connection = ProviderConnection::create(['credential_id' => $credential->id, 'provider' => 'salla', 'connection_name' => $label, 'scope' => 'project_only', 'status' => 'connected']);

        $store = new ExternalAccount;
        $store->forceFill([
            'id' => (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, "ra:{$label}"),
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id,
            'provider' => 'salla', 'account_type' => 'store', 'external_id' => $label, 'name' => $label,
            'currency' => 'USD', 'status' => 'active',
        ])->save();

        return $store;
    }

    private function order(ExternalAccount $store, string $id, float $total, float $refunded, string $placedAt = '2026-06-10'): void
    {
        app(ImportStoreData::class)->orders($store, (string) $this->project->id, [[
            'external_id' => $id, 'status' => 'completed', 'placed_at' => $placedAt.' 12:00:00',
            'currency' => 'USD', 'total' => $total, 'refunded_total' => $refunded,
        ]]);
    }

    /** 1. The figure the chain's last link needs, in the window, as a number the diagnostic can read. */
    public function test_refunds_reach_the_summary_and_are_reported(): void
    {
        $store = $this->store('shop');
        $this->order($store, 'o1', 1000.0, 200.0);
        $this->order($store, 'o2', 500.0, 50.0);

        $data = $this->summary();

        $this->assertEqualsWithDelta(250.0, (float) $data['current']['refunds'], 0.01, 'refunds did not reach the summary');
        $this->assertTrue($data['reported']['refunds'] ?? false, 'the summary does not say refunds were reported');
    }

    /** A refund outside the window is not this window's refund. */
    public function test_refunds_are_counted_in_the_window_only(): void
    {
        $store = $this->store('shop');
        $this->order($store, 'in', 1000.0, 200.0);
        $this->order($store, 'out', 1000.0, 900.0, placedAt: '2026-05-02');

        $this->assertEqualsWithDelta(200.0, (float) $this->summary()['current']['refunds'], 0.01);
    }

    /** 2. No store, no figure — null and unreported, never zero. */
    public function test_a_project_with_no_store_reports_refunds_as_absent_not_zero(): void
    {
        $data = $this->summary();

        $this->assertArrayHasKey('refunds', $data['current'], 'the key must exist so a reader cannot mistake absence for an old payload');
        $this->assertNull($data['current']['refunds'], 'a project with no store had its refunds invented as a number');
        $this->assertFalse($data['reported']['refunds'] ?? true, 'refunds were claimed reported with no store in scope');
    }

    /** 3. The axes refunds cannot honour are declared unapplied, not silently ignored. */
    public function test_the_axes_refunds_do_not_honour_are_declared(): void
    {
        $store = $this->store('shop');
        $this->order($store, 'o1', 1000.0, 200.0);

        $data = $this->summary('&provider=meta&objective=sales');

        $this->assertSame([], $data['refunds']['filter_scope']['applied'] ?? null);
        $this->assertEqualsCanonicalizing(['provider', 'objective'], $data['refunds']['filter_scope']['unapplied'] ?? []);
        /* And the figure is still the project's — declared, not withheld. */
        $this->assertEqualsWithDelta(200.0, (float) $data['current']['refunds'], 0.01);
    }
}
