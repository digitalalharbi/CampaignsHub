<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Connectors\ConnectionCenterService;
use App\Domains\Integrations\Connectors\ConnectorRegistry;
use App\Domains\Integrations\Connectors\Contracts\Connector;
use App\Domains\Integrations\Connectors\Enums\Capability;
use App\Domains\Integrations\Connectors\Enums\ConnectionState;
use App\Domains\Integrations\Connectors\ValueObjects\SyncWindow;
use App\Domains\Integrations\Http\Controllers\ConnectionCenterController;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/**
 * Connection Center: the unified connector framework with HONEST states. Proves every registered
 * provider resolves with its declared capabilities; that a real provider without credentials is
 * "Awaiting External Dependency" and never fabricates a sync or reaches production_verified; that the
 * Sandbox performs a real (offline, labelled) demo sync and reaches sandbox_verified; that token /
 * error states derive correctly; that the read API + route file behave; and that sync history is
 * isolated per tenant.
 *
 * Additive: existing Integrations/Metrics tests must stay green — nothing here mutates them.
 */
final class ConnectionCenterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $projectA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'email' => 'o@agency.test', 'password' => 'secret123']);
        $this->owner->assignRole($role);

        $ws = ClientWorkspace::create(['name' => 'Client', 'slug' => 'client', 'mode' => 'managed']);
        $this->projectA = Project::create(['client_workspace_id' => $ws->id, 'name' => 'Project A', 'status' => 'active']);

        app(TenantContext::class)->forget();
    }

    /** Create a provider connection (with its required credential) under a given tenant. */
    private function connection(string $tenantId, string $provider, array $attrs = []): ProviderConnection
    {
        app(TenantContext::class)->setTenantId($tenantId);

        $credential = new IntegrationCredential([
            'provider' => $provider, 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token-'.$provider);
        $credential->save();

        $connection = ProviderConnection::create(array_merge([
            'credential_id' => $credential->id,
            'provider' => $provider,
            'connection_name' => $provider.' connection',
            'scope' => 'project_only',
            'status' => 'connected',
        ], $attrs));

        app(TenantContext::class)->forget();

        return $connection;
    }

    /** A request whose user() resolves to the given user (for direct controller invocation). */
    private function requestAs(User $user): Request
    {
        $request = Request::create('/', 'POST');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function withContext(callable $fn): mixed
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(ProjectContext::class)->setProjectId($this->projectA->id);
        try {
            return $fn();
        } finally {
            app(TenantContext::class)->forget();
            app(ProjectContext::class)->forget();
        }
    }

    public function test_every_registered_provider_resolves_to_a_connector_with_declared_capabilities(): void
    {
        $registry = app(ConnectorRegistry::class);

        // The full provider set required for the Connection Center.
        $expected = [
            'sandbox', 'meta_ads', 'google_ads', 'tiktok_ads', 'snapchat_ads', 'linkedin_ads', 'x_ads',
            'microsoft_ads', 'pinterest_ads', 'ga4', 'google_tag_manager', 'salla', 'zid', 'shopify',
            'woocommerce', 'crm', 'google_drive',
        ];

        foreach ($expected as $provider) {
            $connector = $registry->get($provider);
            $this->assertInstanceOf(Connector::class, $connector, "Missing connector for {$provider}");
            $this->assertSame($provider, $connector->provider());
            $this->assertNotEmpty($connector->capabilities(), "{$provider} declares no capabilities");

            // Every declared capability is a canonical key.
            foreach ($connector->capabilities() as $capability) {
                $this->assertContains($capability, Capability::values());
            }
        }

        // Only the Sandbox is a real, credentialed connector; every external platform awaits credentials.
        $this->assertTrue($registry->get('sandbox')->hasCredentials());
        $this->assertFalse($registry->get('meta_ads')->hasCredentials());
    }

    public function test_provider_without_credentials_is_awaiting_and_sync_is_a_no_op(): void
    {
        $service = app(ConnectionCenterService::class);
        $connector = $service->connector('meta_ads');

        // No connection → awaiting_credentials, never production_verified.
        $this->assertSame(ConnectionState::AwaitingCredentials, $service->stateFor($connector, null));

        $result = $this->withContext(fn () => $service->sync($connector, null, SyncWindow::lastDays(7)));

        $this->assertFalse($result['result']->success);
        $this->assertSame('failed', $result['run']->status);
        $this->assertSame(0, (int) $result['run']->metrics_upserted);
        $this->assertSame(ConnectionState::AwaitingCredentials, $result['state']);
        $this->assertNotSame(ConnectionState::ProductionVerified, $result['state']);
        // No fabricated metrics were written.
        $this->assertSame(0, DailyMetric::withoutGlobalScopes()->where('provider', 'meta_ads')->count());
    }

    public function test_sandbox_performs_a_real_demo_sync_and_reports_sandbox_verified(): void
    {
        $service = app(ConnectionCenterService::class);
        $connector = $service->connector('sandbox');
        $connection = $this->connection($this->tenant->id, 'sandbox');

        $result = $this->withContext(fn () => $service->sync($connector, $connection, SyncWindow::lastDays(7)));

        $this->assertTrue($result['result']->success);
        $this->assertSame('success', $result['run']->status);
        $this->assertGreaterThan(0, (int) $result['run']->metrics_upserted);
        $this->assertSame(ConnectionState::SandboxVerified, $result['state']);

        // A real (offline, labelled) sync actually landed rows in daily_metrics and a sync run.
        $this->assertGreaterThan(0, DailyMetric::withoutGlobalScopes()->where('provider', 'sandbox')->where('is_demo', true)->count());
        $this->assertDatabaseHas('metric_sync_runs', ['provider' => 'sandbox', 'status' => 'success']);
    }

    public function test_token_expired_and_sync_failed_and_permission_states_derive_correctly(): void
    {
        $service = app(ConnectionCenterService::class);

        $expired = $this->connection($this->tenant->id, 'meta_ads', ['token_expires_at' => now()->subDay()]);
        $this->assertSame(ConnectionState::TokenExpired, $service->stateFor($service->connector('meta_ads'), $expired));

        $failed = $this->connection($this->tenant->id, 'google_ads', ['last_error' => 'Rate limited during sync']);
        $this->assertSame(ConnectionState::SyncFailed, $service->stateFor($service->connector('google_ads'), $failed));

        $permission = $this->connection($this->tenant->id, 'tiktok_ads', ['last_error' => 'Missing permission: ads_read scope']);
        $this->assertSame(ConnectionState::PermissionMissing, $service->stateFor($service->connector('tiktok_ads'), $permission));
    }

    public function test_read_api_lists_connectors_with_capabilities_and_honest_state(): void
    {
        $response = $this->withContext(fn () => app(ConnectionCenterController::class)->index($this->requestAs($this->owner)));
        $data = $response->getData(true)['data'];

        $providers = array_column($data, 'provider');
        $this->assertContains('meta_ads', $providers);
        $this->assertContains('sandbox', $providers);

        $meta = collect($data)->firstWhere('provider', 'meta_ads');
        $this->assertSame('awaiting_credentials', $meta['state']);
        $this->assertTrue($meta['awaiting_external_dependency']);
        $this->assertNotEmpty($meta['capabilities']);

        $sandbox = collect($data)->firstWhere('provider', 'sandbox');
        $this->assertSame('available', $sandbox['state']); // ready, no connection yet
    }

    public function test_read_api_sync_and_history(): void
    {
        $controller = app(ConnectionCenterController::class);

        // Sandbox sync establishes a real connection + records history, reports sandbox_verified.
        $sync = $this->withContext(fn () => $controller->sync($this->requestAs($this->owner), 'sandbox'))->getData(true);
        $this->assertSame('sandbox_verified', $sync['data']['state']);
        $this->assertSame('success', $sync['data']['status']);
        $this->assertGreaterThan(0, $sync['data']['metrics_upserted']);

        // History surfaces the run + data freshness.
        $history = $this->withContext(fn () => $controller->history($this->requestAs($this->owner), 'sandbox'))->getData(true)['data'];
        $this->assertNotEmpty($history['runs']);
        $this->assertSame('success', $history['data_freshness']['last_status']);

        // A real provider syncs honestly as a no-op.
        $noop = $this->withContext(fn () => $controller->sync($this->requestAs($this->owner), 'meta_ads'))->getData(true);
        $this->assertSame('awaiting_credentials', $noop['data']['state']);
        $this->assertSame('failed', $noop['data']['status']);
    }

    public function test_read_api_requires_integrations_permission(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Viewer', 'slug' => 'viewer']);
        $role->givePermissionTo('projects.view', 'projects.view.all'); // can see the project, but not integrations
        $viewer = User::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'email' => 'v@agency.test', 'password' => 'secret123']);
        $viewer->assignRole($role);
        app(TenantContext::class)->forget();

        $status = null;
        try {
            $this->withContext(fn () => app(ConnectionCenterController::class)->index($this->requestAs($viewer)));
        } catch (HttpExceptionInterface $e) {
            $status = $e->getStatusCode();
        }

        $this->assertSame(403, $status);
    }

    public function test_connections_route_file_declares_expected_routes(): void
    {
        // The route file is intentionally NOT wired into routes/api.php (the orchestrator does that);
        // load it here to prove it declares the expected project-scoped, permission-gated endpoints.
        Route::prefix('api/v1')->group(fn () => require base_path('routes/api/connections.php'));
        Route::getRoutes()->refreshNameLookups();

        foreach (['index', 'sync', 'history'] as $name) {
            $this->assertTrue(Route::has("projects.connections.{$name}"), "Missing route projects.connections.{$name}");
        }

        $index = Route::getRoutes()->getByName('projects.connections.index');
        $this->assertSame('api/v1/projects/{project}/connections', $index->uri());
        $this->assertContains('project', $index->gatherMiddleware());
        $this->assertContains('tenant', $index->gatherMiddleware());
    }

    public function test_sync_history_is_isolated_per_tenant(): void
    {
        $service = app(ConnectionCenterService::class);
        $connector = $service->connector('sandbox');

        // Tenant A runs a sandbox sync for project A.
        $connectionA = $this->connection($this->tenant->id, 'sandbox');
        $this->withContext(fn () => $service->sync($connector, $connectionA, SyncWindow::lastDays(7)));

        // A second tenant with its own project + sync.
        $tenantB = Tenant::create(['name' => 'Rival', 'slug' => 'rival', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenantB->id);
        $wsB = ClientWorkspace::create(['name' => 'CB', 'slug' => 'cb', 'mode' => 'managed']);
        $projectB = Project::create(['client_workspace_id' => $wsB->id, 'name' => 'B', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $connectionB = $this->connection($tenantB->id, 'sandbox');
        app(TenantContext::class)->setTenantId($tenantB->id);
        app(ProjectContext::class)->setProjectId($projectB->id);
        $service->sync($connector, $connectionB, SyncWindow::lastDays(7));
        // Tenant B only sees its own sandbox run (global tenant + project scope).
        $this->assertSame(1, MetricSyncRun::where('provider', 'sandbox')->count());
        app(TenantContext::class)->forget();
        app(ProjectContext::class)->forget();

        // Tenant A likewise only sees its own.
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(ProjectContext::class)->setProjectId($this->projectA->id);
        $this->assertSame(1, MetricSyncRun::where('provider', 'sandbox')->count());
        app(TenantContext::class)->forget();
        app(ProjectContext::class)->forget();
    }
}
