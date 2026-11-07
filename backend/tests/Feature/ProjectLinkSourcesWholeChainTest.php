<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Services\StoreSyncer;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Measurement\Ga4NotSelected;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Measurement\Ga4PropertySync;
use App\Domains\Integrations\Measurement\MeasurementDailyMetric;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationWebhookEvent;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Measurement\SiteMeasurementService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * INTEG-PROJECT-LINK-STORES-GA4-001 (Owner 2026-11-06) — the whole chain for ONE store and ONE property, end to
 * end, the way {@see ProjectLinkWholeChainTest} holds it for an ad account:
 *
 *     project A → «ربط» → the platform's own page → the store / the property → confirmed
 *       → a read (and, for the store, a signed webhook through the existing inbox)
 *       → A's funnel / site analytics / report section show it
 *       → project B and tenant B show nothing
 *       → unlink → new data stops for A, what was stored stays
 *
 * Analytics and reports are NOT changed by this work: they read the same tables by the same ids. This holds
 * that a source linked through the journey reaches them — and reaches nobody else's.
 */
final class ProjectLinkSourcesWholeChainTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'salla-webhook-secret';

    private Tenant $tenant;

    private User $operator;

    private Project $a;

    private Project $b;

    /** @var list<string> the order ids the store answers with, read at request time */
    private array $orders = ['o1'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        [$this->tenant, $this->operator] = $this->agency('Agency');
        $this->a = $this->project($this->tenant, 'Project A');
        $this->b = $this->project($this->tenant, 'Project B');

        app(ProviderConfigurationService::class)->save('salla', [
            'client_id' => 'app-1', 'client_secret' => 'salla-secret', 'webhook_secret' => self::WEBHOOK_SECRET,
        ]);
        config()->set('measurement_platforms.platforms.ga4.client_id', 'test-client-id');
        config()->set('measurement_platforms.platforms.ga4.client_secret', 'test-client-secret');

        app(TenantContext::class)->forget();
        $this->fakePlatforms();
    }

    public function test_a_store_linked_from_project_a_reaches_as_funnel_through_sync_and_webhook_and_nobody_elses(): void
    {
        // ── project → «ربط» → سلة → Salla's authorise page → the store → confirmed ────────────
        $store = $this->linkThroughTheJourney('salla', $this->a, 'store-9');
        $this->assertSame('store', $store->account_type);
        $this->assertSame((string) $this->a->id, (string) ProviderConnection::withoutGlobalScopes()->findOrFail($store->provider_connection_id)->project_id);
        $this->assertSame('SAR', $store->currency);

        // ── a read ─────────────────────────────────────────────────────────────────────────
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $run = app(StoreSyncer::class)->sync($store, Carbon::now()->subDays(7), Carbon::now());
        app(TenantContext::class)->forget();
        $this->assertContains($run->status, ['success', 'partial_mapping'], (string) $run->error);
        $this->assertSame([(string) $this->a->id], CommerceOrder::withoutGlobalScopes()->pluck('project_id')->map(strval(...))->unique()->values()->all());

        // ── a signed delivery through the existing inbox reaches the same project ───────────────
        $this->orders = ['o1', 'o2'];
        $this->delivery('order.created', 'o2', 'evt-1')->assertOk();
        $event = IntegrationWebhookEvent::query()->where('provider', 'salla')->where('external_event_id', 'evt-1')->sole();
        $this->assertSame('processed', $event->status, (string) $event->error);
        $this->assertSame((string) $store->id, (string) $event->external_account_id);
        $this->assertSame(['o1', 'o2'], CommerceOrder::withoutGlobalScopes()->where('project_id', $this->a->id)->orderBy('external_id')->pluck('external_id')->all());

        // ── A's funnel shows it; B's shows nothing; the store list of B does not name it ────────
        $this->assertSame(2, $this->read($this->a, 'commerce/funnel')->json('data.totals.orders'));
        $this->assertSame(0, (int) $this->read($this->b, 'commerce/funnel')->json('data.totals.orders'));
        $this->assertSame(0, CommerceOrder::withoutGlobalScopes()->where('project_id', $this->b->id)->count());
        $boardB = $this->as($this->operator, $this->tenant)->getJson('/api/v1/commerce/stores?project_id='.$this->b->id)->assertOk();
        $this->assertStringNotContainsString('store-9', $boardB->getContent());

        // ── tenant B: nothing, by every route ───────────────────────────────────────────────────
        [$rival, $rivalOperator] = $this->agency('Rival');
        $this->project($rival, 'Rival Project');
        app(TenantContext::class)->forget();
        $this->as($rivalOperator, $rival)->withHeader('X-Project-Id', $this->a->id)
            ->getJson("/api/v1/projects/{$this->a->id}/commerce/funnel")->assertNotFound();
        $this->assertStringNotContainsString('store-9', $this->as($rivalOperator, $rival)->getJson('/api/v1/commerce/stores')->assertOk()->getContent());
        $this->as($rivalOperator, $rival)->patchJson("/api/v1/commerce/stores/{$store->id}", ['project_id' => null])->assertNotFound();

        // ── unlink: new data stops for A; what was stored stays ────────────────────────────────
        $this->as($this->operator, $this->tenant)->withHeader('X-Project-Id', $this->a->id)
            ->postJson("/api/v1/projects/{$this->a->id}/integrations/accounts/{$store->id}/unlink")->assertOk();
        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->where('is_active', true)->count());

        $this->orders = ['o1', 'o2', 'o3'];
        $this->delivery('order.created', 'o3', 'evt-2')->assertOk();
        $ignored = IntegrationWebhookEvent::query()->where('provider', 'salla')->where('external_event_id', 'evt-2')->sole();
        $this->assertSame(IntegrationWebhookEvent::IGNORED_UNBOUND, $ignored->status);
        $this->assertSame(0, CommerceOrder::withoutGlobalScopes()->where('external_id', 'o3')->count(), 'an unlinked store kept filing orders');
        $this->assertSame(2, CommerceOrder::withoutGlobalScopes()->where('project_id', $this->a->id)->count(), 'the stored orders were not kept');
        $this->as($this->operator, $this->tenant)->postJson("/api/v1/commerce/stores/{$store->id}/sync")->assertStatus(409);
    }

    public function test_a_property_linked_from_project_a_reaches_as_site_analytics_and_report_section_and_nobody_elses(): void
    {
        // ── project → «ربط» → GA4 → Google's sign-in → the property → confirmed ─────────────────
        $property = $this->linkThroughTheJourney('ga4', $this->a, '111');
        $this->assertSame(Ga4PropertyDiscovery::ACCOUNT_TYPE, $property->account_type);
        $this->assertSame((string) $this->a->id, (string) ProviderConnection::withoutGlobalScopes()->findOrFail($property->provider_connection_id)->project_id);
        // Only the confirmed property exists: the two others this sign-in can read were never written.
        $this->assertSame(['111'], ExternalAccount::withoutGlobalScopes()->pluck('external_id')->all());

        // ── a read ─────────────────────────────────────────────────────────────────────────
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $out = app(Ga4PropertySync::class)->sync($property, 2);
        app(TenantContext::class)->forget();
        $this->assertSame((string) $this->a->id, (string) $out['project_id']);
        $this->assertSame([(string) $this->a->id], MeasurementDailyMetric::withoutGlobalScopes()->pluck('project_id')->map(strval(...))->unique()->values()->all());
        $stored = MeasurementDailyMetric::withoutGlobalScopes()->count();
        $this->assertGreaterThan(0, $stored);

        // ── A's site analytics and A's report section show it ───────────────────────────────────
        $window = 'from=2026-10-01&to=2026-10-10';
        $site = $this->read($this->a, 'measurement/analytics?'.$window)->json('data');
        $this->assertNotContains($site['state'], ['not_connected', 'not_selected']);
        $this->assertEquals(8200, $site['totals']['sessions']);

        $section = $this->reportSection($this->a);
        $this->assertNotNull($section);
        // REPORT-WEBSITE-GAP-READING-001 — the report carries the reading, never the property's id or name.
        $this->assertArrayNotHasKey('id', $section['property']);
        $this->assertSame(8200.0, (float) $section['totals']['sessions']);

        // ── B shows nothing ─────────────────────────────────────────────────────────────────────
        $this->assertContains($this->read($this->b, 'measurement/analytics?'.$window)->json('data.state'), ['not_connected', 'not_selected']);
        $this->assertNull($this->reportSection($this->b));
        $listB = $this->as($this->operator, $this->tenant)->getJson('/api/v1/measurement/properties?project_id='.$this->b->id)->assertOk();
        $this->assertStringNotContainsString('Acme Store', $listB->getContent());

        // ── tenant B: nothing ───────────────────────────────────────────────────────────────────
        [$rival, $rivalOperator] = $this->agency('Rival');
        app(TenantContext::class)->forget();
        $this->as($rivalOperator, $rival)->withHeader('X-Project-Id', $this->a->id)
            ->getJson("/api/v1/projects/{$this->a->id}/measurement/analytics?".$window)->assertNotFound();
        $this->assertStringNotContainsString('Acme Store', $this->as($rivalOperator, $rival)->getJson('/api/v1/measurement/properties')->assertOk()->getContent());
        $this->as($rivalOperator, $rival)->postJson("/api/v1/measurement/properties/{$property->id}/sync")->assertNotFound();

        // ── unlink: nothing new is read for A; what was stored stays ────────────────────────────
        $this->as($this->operator, $this->tenant)->withHeader('X-Project-Id', $this->a->id)
            ->postJson("/api/v1/projects/{$this->a->id}/integrations/accounts/{$property->id}/unlink")->assertOk();

        app(TenantContext::class)->setTenantId($this->tenant->id);
        try {
            app(Ga4PropertySync::class)->sync($property->refresh(), 2);
            $this->fail('an unlinked property was read');
        } catch (Ga4NotSelected) {
            // refused: nobody reads a property no project chose
        }
        app(TenantContext::class)->forget();
        $this->assertSame($stored, MeasurementDailyMetric::withoutGlobalScopes()->count(), 'history is kept, and nothing is added');
        $this->assertNull($this->reportSection($this->a), 'an unlinked property kept reporting');
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────────

    private function linkThroughTheJourney(string $provider, Project $project, string $externalId): ExternalAccount
    {
        $start = $provider === 'ga4' ? '/api/v1/integrations/measurement/ga4/oauth/start' : "/api/v1/integrations/commerce/{$provider}/oauth/start";
        $return = $provider === 'ga4' ? '/api/v1/oauth/measurement/ga4/callback' : "/api/v1/oauth/commerce/{$provider}/callback";

        $url = $this->as($this->operator, $this->tenant)->postJson($start, ['project_id' => $project->id, 'return_portal' => 'agency'])
            ->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $sent);

        $location = (string) $this->get("{$return}?code=abc&state={$sent['state']}")->headers->get('Location');
        $this->assertStringContainsString("/agency/projects/{$project->id}/integrations?", $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $back);
        app(TenantContext::class)->forget();

        $base = "/api/v1/projects/{$project->id}/integrations/link/{$back['link']}";
        $this->assertTrue($this->as($this->operator, $this->tenant)->withHeader('X-Project-Id', $project->id)->getJson($base)->assertOk()->json('data.pending'));
        $this->assertContains($externalId, array_column(
            $this->as($this->operator, $this->tenant)->withHeader('X-Project-Id', $project->id)->getJson($base.'/sources')->assertOk()->json('data.sources'),
            'external_id',
        ));
        $id = $this->as($this->operator, $this->tenant)->withHeader('X-Project-Id', $project->id)
            ->postJson($base.'/sources', ['external_id' => $externalId])->assertCreated()->json('data.account_id');
        app(TenantContext::class)->forget();

        return ExternalAccount::withoutGlobalScopes()->findOrFail($id);
    }

    private function delivery(string $topic, string $orderId, string $eventId): TestResponse
    {
        $raw = (string) json_encode([
            'event' => $topic, 'merchant' => 'store-9', 'event_id' => $eventId,
            'created_at' => Carbon::now()->toIso8601String(),
            'data' => ['id' => $orderId, 'status' => ['slug' => 'completed']],
        ]);

        $response = $this->call('POST', '/api/v1/webhooks/commerce/salla', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SALLA_SIGNATURE' => hash_hmac('sha256', $raw, self::WEBHOOK_SECRET),
        ], $raw);
        app(TenantContext::class)->forget();

        return $response;
    }

    /** @return array<string,mixed>|null the site-measurement section a report of this project carries */
    private function reportSection(Project $project): ?array
    {
        return app(SiteMeasurementService::class)->build(
            (string) $this->tenant->id, (string) $project->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-10'),
        );
    }

    private function read(Project $project, string $path): TestResponse
    {
        return $this->as($this->operator, $this->tenant)->withHeader('X-Project-Id', $project->id)
            ->getJson("/api/v1/projects/{$project->id}/{$path}")->assertOk();
    }

    private function as(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user, 'sanctum')->withHeader('X-Tenant-ID', $tenant->id);
    }

    private function fakePlatforms(): void
    {
        Http::preventStrayRequests();
        $page = ['pagination' => ['currentPage' => 1, 'totalPages' => 1]];

        Http::fake(function (HttpRequest $request) use ($page) {
            $url = $request->url();

            return match (true) {
                // ── Salla ──
                str_contains($url, 'accounts.salla.sa/oauth2/token') => Http::response(['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 1209600]),
                str_contains($url, '/store/info') => Http::response(['data' => ['id' => 'store-9', 'name' => 'متجر تجريبي', 'domain' => 'demo.salla.sa', 'currency' => 'SAR']]),
                str_contains($url, '/orders') => Http::response(['data' => array_map(static fn (string $id): array => [
                    'id' => $id, 'reference_id' => 1, 'status' => ['slug' => 'completed'],
                    'date' => ['date' => Carbon::now()->subDay()->format('Y-m-d H:i:s.000000'), 'timezone' => 'Asia/Riyadh'],
                    'amounts' => ['total' => ['amount' => 100, 'currency' => 'SAR']], 'items' => [],
                ], $this->orders)] + $page),
                str_contains($url, 'api.salla.dev') => Http::response(['data' => []] + $page),
                // ── Google ──
                str_contains($url, 'oauth2.googleapis.com') => Http::response(['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600]),
                str_contains($url, 'analyticsadmin.googleapis.com') && str_contains($url, 'accountSummaries') => Http::response(['accountSummaries' => [
                    ['account' => 'accounts/100', 'displayName' => 'Acme Group', 'propertySummaries' => [
                        ['property' => 'properties/111', 'displayName' => 'Acme Store'],
                        ['property' => 'properties/333', 'displayName' => 'Acme Blog'],
                    ]],
                    ['account' => 'accounts/200', 'displayName' => 'Nova Group', 'propertySummaries' => [
                        ['property' => 'properties/222', 'displayName' => 'Nova Shop'],
                    ]],
                ]]),
                str_contains($url, 'analyticsadmin.googleapis.com') => Http::response(['displayName' => 'Acme Store', 'timeZone' => 'Asia/Riyadh', 'currencyCode' => 'SAR']),
                str_contains($url, 'analyticsdata.googleapis.com') => Http::response([
                    'metricHeaders' => array_map(static fn (string $n): array => ['name' => $n], ['transactions', 'sessions', 'purchaseRevenue', 'engagementRate']),
                    'rows' => array_map(static fn (string $date): array => [
                        'dimensionValues' => [['value' => $date]],
                        'metricValues' => array_map(static fn (string $v): array => ['value' => $v], ['17', '4100', '8900.5', '0.6134']),
                    ], ['20261007', '20261008']),
                ]),
                default => Http::response([], 404),
            };
        });
    }

    /** @return array{0: Tenant, 1: User} */
    private function agency(string $name): array
    {
        $tenant = Tenant::create(['name' => $name, 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create(['name' => $name.' Owner', 'email' => 'o-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($user, $tenant, Portal::Agency);
        $user->assignRole($role);

        return [$tenant, $user];
    }

    private function project(Tenant $tenant, string $name): Project
    {
        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => $name, 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        return Project::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $workspace->id, 'name' => $name, 'status' => 'active',
        ]);
    }
}
