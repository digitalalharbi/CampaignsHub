<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Catalogue\ProviderCapability;
use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Metrics\Enums\SyncRunStatus;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\Support\Providers\FakeAdsConnector;
use Tests\TestCase;

/**
 * INTEG-CAPABILITY-CONTRACT-001 §5 gate 5 — a new provider needs no change to the engine.
 *
 * `FakeAdsConnector` lives in `tests/` and nothing under `app/` names it, which the last case asserts.
 * It arrives the way a provider arrives: a catalogue declaration, a connector in the registry, and a
 * protocol entry in config. From there the engine's own paths carry it: the real discovery, the real
 * `POST bindings` endpoint, the real sweeps and jobs, the real `daily_metrics`.
 *
 * Two accounts are discovered and one is bound, so this also holds gate 1 for a provider the engine
 * has never met: the other account is catalogued and never asked about.
 */
final class NewProviderNeedsNoEngineEditTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@agency.test', 'password' => 'secret123']);
        $this->grantMembership($this->user, $this->tenant);
        $this->user->assignRole($role);

        $workspace = ClientWorkspace::create(['name' => 'Client', 'slug' => 'client-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active']);

        // The three things a provider brings.
        ProviderCatalogue::register(FakeAdsConnector::definition());
        config()->set('ad_platforms.platforms.'.FakeAdsConnector::KEY, FakeAdsConnector::config());
        app(AdvertisingConnectorRegistry::class)->register(new FakeAdsConnector);

        Http::fake([
            'api.fakeads.test/v1/accounts' => Http::response(['accounts' => [
                ['id' => 'fake-bound', 'name' => 'Bound', 'currency' => 'SAR'],
                ['id' => 'fake-other', 'name' => 'Other client', 'currency' => 'SAR'],
            ]]),
            'api.fakeads.test/v1/accounts/fake-bound/campaigns' => Http::response(['campaigns' => [['id' => 'cmp-1', 'name' => 'Launch']]]),
            'api.fakeads.test/v1/accounts/fake-bound/insights*' => Http::response(['rows' => [
                ['campaign' => 'cmp-1', 'day' => now()->subDay()->toDateString(), 'spend' => 120.5, 'impressions' => 9000, 'clicks' => 300],
            ]]),
        ]);
    }

    protected function tearDown(): void
    {
        // The catalogue is memoised statically, so a registered provider would outlive this test.
        ProviderCatalogue::flush();

        parent::tearDown();
    }

    public function test_discovery_binding_and_one_sync_run_through_the_engine_untouched(): void
    {
        $registry = app(AdvertisingConnectorRegistry::class);
        $adapter = $registry->adapter(FakeAdsConnector::KEY);
        $this->assertNotNull($adapter);

        // Discovery: through the contract, into `external_accounts`, with nothing selected.
        $connection = $this->connect();
        $found = $adapter->discover($connection);

        $this->assertSame(['ad_account'], $found->accountTypes());
        $this->assertSame(['fake-bound', 'fake-other'], $found->resources->pluck('external_id')->sort()->values()->all());
        $this->assertSame(0, MetricSyncRun::withoutGlobalScopes()->count(), 'discovery synced something');

        // Nothing bound: the engine's sweep has nothing to ask.
        Artisan::call('integrations:sync', ['--provider' => FakeAdsConnector::KEY]);
        $this->assertSame(0, MetricSyncRun::withoutGlobalScopes()->count());
        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), '/insights'));

        // Binding: the same endpoint the wizard calls.
        $bound = $found->resources->firstWhere('external_id', 'fake-bound');
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/integrations/bindings", [
                'external_account_id' => $bound->id, 'purpose' => 'advertising',
            ])->assertCreated();

        // One sync: the two scheduled commands, which queue the real jobs.
        app(TenantContext::class)->forget();
        Artisan::call('integrations:sync-structure', ['--provider' => FakeAdsConnector::KEY]);
        Artisan::call('integrations:sync', ['--provider' => FakeAdsConnector::KEY]);

        $runs = MetricSyncRun::withoutGlobalScopes()->get();
        $this->assertCount(1, $runs, 'one bound account is one run');
        $this->assertSame(SyncRunStatus::Success->value, (string) $runs->first()->getRawOriginal('status'), (string) $runs->first()->error);
        $this->assertSame((string) $bound->id, (string) $runs->first()->external_account_id);

        $metrics = DailyMetric::withoutGlobalScopes()->where('external_account_id', $bound->id)->get();
        $this->assertEqualsCanonicalizing(['spend', 'impressions', 'clicks'], $metrics->pluck('metric_key')->all());

        $spend = $metrics->firstWhere('metric_key', 'spend');
        $this->assertSame((string) $this->project->id, (string) $spend->project_id);
        $this->assertSame(FakeAdsConnector::KEY, (string) $spend->provider);
        $this->assertEqualsWithDelta(120.5, (float) $spend->value, 0.001);
        $this->assertSame(1, ExternalCampaign::withoutGlobalScopes()->where('external_account_id', $bound->id)->count());

        // The account nobody selected was catalogued and never asked about.
        $other = ExternalAccount::withoutGlobalScopes()->where('external_id', 'fake-other')->sole();
        $this->assertSame(0, DailyMetric::withoutGlobalScopes()->where('external_account_id', $other->id)->count());
        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), 'fake-other'));
    }

    /**
     * The sweep's own selection, apart from the job's.
     *
     * The job re-proves the binding when it runs, so the end-to-end case above cannot see a sweep that
     * queued every discovered account: those jobs return silently. Mutating the sweep proved exactly
     * that, so the queue is asserted here.
     */
    public function test_the_sweep_queues_only_what_was_bound(): void
    {
        $found = app(AdvertisingConnectorRegistry::class)->adapter(FakeAdsConnector::KEY)->discover($this->connect());
        $bound = $found->resources->firstWhere('external_id', 'fake-bound');

        Queue::fake();
        Artisan::call('integrations:sync', ['--provider' => FakeAdsConnector::KEY]);
        Queue::assertNothingPushed();

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/integrations/bindings", [
                'external_account_id' => $bound->id, 'purpose' => 'advertising',
            ])->assertCreated();

        app(TenantContext::class)->forget();
        Queue::fake();
        Artisan::call('integrations:sync', ['--provider' => FakeAdsConnector::KEY]);

        Queue::assertPushed(SyncAccountMetricsJob::class, 1);
        Queue::assertPushed(SyncAccountMetricsJob::class, static fn (SyncAccountMetricsJob $job): bool => $job->accountId === (string) $bound->id);
    }

    /** The same sync through the contract's own door, for the same answer. */
    public function test_the_adapter_syncs_one_resource_through_the_syncer(): void
    {
        $adapter = app(AdvertisingConnectorRegistry::class)->adapter(FakeAdsConnector::KEY);
        $found = $adapter->discover($this->connect());
        $resource = $found->resources->firstWhere('external_id', 'fake-other');

        $outcome = $adapter->syncResource($resource, now()->subDays(2), now());

        $this->assertSame(SyncRunStatus::AwaitingAssignment->value, $outcome->status, 'an unbound resource was synced');
        $this->assertSame(0, $outcome->records);
    }

    /** It claims almost nothing, and nothing asks more of it. */
    public function test_negotiation_answers_for_it_and_refuses_what_it_did_not_declare(): void
    {
        $registry = app(AdvertisingConnectorRegistry::class);

        $this->assertTrue($registry->supports(FakeAdsConnector::KEY, ProviderCapability::Discovery));
        $this->assertContains(FakeAdsConnector::KEY, $registry->supporting(ProviderCapability::Discovery));

        foreach ([
            ProviderCapability::RefreshToken, ProviderCapability::TokenExpiry, ProviderCapability::TokenExchange,
            ProviderCapability::Webhooks, ProviderCapability::Hierarchy, ProviderCapability::EntityGrains,
            ProviderCapability::CreativeInsights, ProviderCapability::PeriodReach, ProviderCapability::AbandonedCarts,
            ProviderCapability::IncrementalCursor,
        ] as $capability) {
            $this->assertFalse($registry->supports(FakeAdsConnector::KEY, $capability), $capability->value);
        }

        $this->assertNull($registry->periodReach(FakeAdsConnector::KEY));
    }

    /** A registration cannot replace a real provider, and a connector cannot arrive undeclared. */
    public function test_registration_cannot_take_over_a_provider_or_skip_the_declaration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProviderCatalogue::register(FakeAdsConnector::definition());
    }

    public function test_a_connector_nothing_declares_is_refused(): void
    {
        ProviderCatalogue::flush();

        $this->expectException(InvalidArgumentException::class);
        app(AdvertisingConnectorRegistry::class)->register(new FakeAdsConnector);
    }

    /** The claim itself: the engine does not know this provider exists. */
    public function test_nothing_under_app_names_the_fake_provider(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() === 'php') {
                $this->assertStringNotContainsStringIgnoringCase(FakeAdsConnector::KEY, (string) file_get_contents($file->getPathname()), $file->getPathname());
            }
        }
    }

    private function connect(): ProviderConnection
    {
        return app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->getKey(),
            provider: FakeAdsConnector::KEY,
            tokens: new OAuthTokens('KEY'),
            connectionName: 'Fake',
            externalOwnerId: 'owner',
            credentialType: 'api_key',
        );
    }
}
