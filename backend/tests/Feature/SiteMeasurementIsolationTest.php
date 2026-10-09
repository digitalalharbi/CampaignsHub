<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Measurement\MeasurementDailyMetric;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Measurement\SiteMeasurementService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * GA4-INTEGRATION-001 × ACCOUNT-SCOPE-ISOLATION-001 — one client's site is never another client's.
 *
 * The tenant fence is the easy half and is already held. The one that matters inside an agency is the
 * CLIENT fence: client A and client B are the same tenant, the same Google identity commonly reaches
 * both their Analytics properties, and «client A's figures in client B's report» is the failure an
 * agency would never be able to explain. It is the same rule as «Client A's logo must never appear in
 * client B's report», told about numbers instead of marks.
 *
 * So these cases are about what the report builder REFUSES to return, and they are written against
 * `SiteMeasurementService` because that is the one door every report surface goes through.
 */
final class SiteMeasurementIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $acme;

    private Project $nova;

    private ProviderConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->acme = $this->project('Acme');
        $this->nova = $this->project('Nova');

        $this->connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'ga4',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: 'Google Analytics 4',
        );
    }

    /** The agency's one Google login reaches both clients' properties. Each report sees only its own. */
    public function test_one_clients_measured_site_never_reaches_another_clients_report(): void
    {
        $this->measured($this->acme, '111', 'Acme Store', sessions: 9000);
        $this->measured($this->nova, '222', 'Nova Shop', sessions: 400);

        $acme = app(SiteMeasurementService::class)->build(
            $this->tenant->id, $this->acme->id, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'),
        );
        $nova = app(SiteMeasurementService::class)->build(
            $this->tenant->id, $this->nova->id, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'),
        );

        $this->assertSame('111', $acme['property']['id']);
        $this->assertSame(9000.0, $acme['totals']['sessions']);

        $this->assertSame('222', $nova['property']['id']);
        $this->assertSame(400.0, $nova['totals']['sessions'], 'one client\'s sessions were summed into another\'s');
    }

    /**
     * The case the `project_id` predicate actually defends, and the reason it is not redundant.
     *
     * The cross-client test above passes without it: two clients have two different properties, so
     * `external_account_id` alone separates them. Mutating the predicate away did not fail it — which
     * is worth knowing, because a guard that cannot fail is not a guard.
     *
     * This is the case that needs it. ONE property moved from Acme's project to Nova's — a real
     * operation, `detach` then `bind`, and the only one in this product that leaves two projects'
     * history under a single account id. Without the project predicate Nova's report would open on
     * Acme's months of traffic, filed under a client who never had it.
     */
    public function test_a_rebound_property_does_not_carry_its_old_projects_history(): void
    {
        $this->measured($this->acme, '111', 'Acme Store', sessions: 9000);

        $account = ExternalAccount::withoutGlobalScopes()->where('external_id', '111')->firstOrFail();

        // Detach from Acme, bind to Nova — the operator moving a property between clients.
        ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->update(['is_active' => false]);

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->nova->client_workspace_id,
            'project_id' => $this->nova->id,
            'external_account_id' => $account->getKey(),
            'provider' => 'ga4',
            'purpose' => 'analytics',
            'is_active' => true,
        ]);

        // One day read since the move, under Nova.
        MeasurementDailyMetric::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->nova->id,
            'external_account_id' => $account->getKey(),
            'property_id' => '111',
            'metric_date' => '2026-07-20',
            'metric_key' => 'sessions',
            'value' => 120,
            'currency' => null,
            'timezone' => 'Asia/Riyadh',
        ]);

        $nova = app(SiteMeasurementService::class)->build(
            $this->tenant->id, $this->nova->id, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'),
        );

        $this->assertSame(
            120.0,
            $nova['totals']['sessions'],
            'the new client\'s report carried the previous client\'s traffic on the same property',
        );
        $this->assertSame(1, $nova['days']);
    }

    /**
     * Detaching a property stops the report, and does not delete the history.
     *
     * The same rule the ad accounts follow: a row is a project's figure only while its account is
     * actively selected for that project — hidden, never destroyed, and back on re-selection.
     */
    public function test_a_detached_property_leaves_the_report_without_losing_its_history(): void
    {
        $this->measured($this->acme, '111', 'Acme Store', sessions: 9000);

        ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('project_id', $this->acme->id)
            ->update(['is_active' => false]);

        $this->assertNull(
            app(SiteMeasurementService::class)->build(
                $this->tenant->id, $this->acme->id, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'),
            ),
            'a detached property kept reporting',
        );

        // The days are still there — this is a hidden section, not a deletion.
        $this->assertSame(1, MeasurementDailyMetric::withoutGlobalScopes()->where('property_id', '111')->count());
    }

    /** Another tenant asking for this project's id gets nothing, not this project's figures. */
    public function test_another_tenant_cannot_read_this_projects_measured_site(): void
    {
        $this->measured($this->acme, '111', 'Acme Store', sessions: 9000);

        $other = Tenant::create(['name' => 'Other', 'slug' => 'ot-'.uniqid(), 'status' => 'active']);

        $this->assertNull(app(SiteMeasurementService::class)->build(
            $other->id, $this->acme->id, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'),
        ));
    }

    /** A link with no project in its scope reads no site at all, rather than the first one it finds. */
    public function test_a_link_with_no_project_reads_no_site(): void
    {
        $this->measured($this->acme, '111', 'Acme Store', sessions: 9000);

        $this->assertNull(app(SiteMeasurementService::class)->build(
            $this->tenant->id, '', Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'),
        ));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function project(string $name): Project
    {
        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => strtolower($name).'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        return Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => $name, 'status' => 'active',
        ]);
    }

    private function measured(Project $project, string $propertyId, string $name, float $sessions): void
    {
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $project->client_workspace_id,
            'provider_connection_id' => $this->connection->getKey(),
            'provider' => 'ga4',
            'account_type' => Ga4PropertyDiscovery::ACCOUNT_TYPE,
            'external_id' => $propertyId,
            'name' => $name,
            'timezone' => 'Asia/Riyadh',
            'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $project->client_workspace_id,
            'project_id' => $project->id,
            'external_account_id' => $account->id,
            'provider' => 'ga4',
            'purpose' => 'analytics',
            'is_active' => true,
        ]);

        MeasurementDailyMetric::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $project->id,
            'external_account_id' => $account->getKey(),
            'property_id' => $propertyId,
            'metric_date' => '2026-07-15',
            'metric_key' => 'sessions',
            'value' => $sessions,
            'currency' => null,
            'timezone' => 'Asia/Riyadh',
        ]);
    }
}
