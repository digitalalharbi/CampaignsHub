<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Models\CommerceProduct;
use App\Domains\Commerce\Services\StoreSyncer;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ACCOUNT-SCOPE-ISOLATION-001 — a store sync imports only when the token's store IS the bound store.
 *
 * ## The leak
 *
 * Salla and Zid take a `$storeId` on every fetch and use it on none: the token decides which store
 * answers, because both platforms authorise ONE store per consent. And `TokenVault::open()` keeps ONE
 * connection per (tenant, provider, client workspace) and REPLACES its token on each consent. So a
 * merchant who connects a second store puts store B's token on the connection store A is bound
 * through — and A's next sweep fetches B's orders, customers, products and carts, files them under A
 * and under A's project, and nothing in the payload says otherwise.
 *
 * `fetchStores()` is the one call that names the store the token reaches. It is asked first now, and a
 * store whose answer is not the bound store's own id is refused before a single row is fetched.
 */
final class CommerceStoreIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Shop', 'slug' => 'shop-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active',
        ]);

        foreach (['salla', 'zid'] as $platform) {
            foreach (PlatformCredentials::for($platform)->requires() as $key) {
                config()->set("commerce_platforms.platforms.{$platform}.{$key}", "test-{$key}");
            }
        }
    }

    public function test_a_salla_token_that_reaches_a_different_store_imports_nothing(): void
    {
        $store = $this->store('salla', 'store_a');

        Http::fake([
            'api.salla.dev/*/store/info' => Http::response(['data' => ['id' => 'store_b', 'name' => 'The other shop']]),
            'api.salla.dev/*' => Http::response(['data' => [$this->sallaOrder()], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]),
        ]);

        $run = app(StoreSyncer::class)->sync($store, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-05'));

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('different store', (string) $run->error);
        $this->assertSame(0, CommerceOrder::withoutGlobalScopes()->count(), "another store's orders were filed under the bound store");
        $this->assertSame(0, CommerceProduct::withoutGlobalScopes()->count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/orders'));
    }

    public function test_a_zid_token_that_reaches_a_different_store_imports_nothing(): void
    {
        $store = $this->store('zid', 'zid_a');

        Http::fake([
            'api.zid.sa/*/managers/account/profile' => Http::response(['user' => ['store' => ['id' => 'zid_b', 'title' => 'x']]]),
            'api.zid.sa/*' => Http::response(['results' => [], 'orders' => []]),
        ]);

        $run = app(StoreSyncer::class)->sync($store, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-05'));

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('different store', (string) $run->error);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/orders'));
    }

    public function test_the_bound_store_still_syncs_when_the_token_reaches_it(): void
    {
        $store = $this->store('salla', 'store_a');

        Http::fake([
            'api.salla.dev/*/store/info' => Http::response(['data' => ['id' => 'store_a', 'name' => 'Ours']]),
            'api.salla.dev/*/orders*' => Http::response(['data' => [$this->sallaOrder()], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]),
            'api.salla.dev/*' => Http::response(['data' => [], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]),
        ]);

        $run = app(StoreSyncer::class)->sync($store, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-05'));

        $this->assertNotSame('failed', $run->status, (string) $run->error);
        $this->assertSame(1, CommerceOrder::withoutGlobalScopes()->where('external_account_id', $store->getKey())->count());
    }

    public function test_a_token_that_names_no_store_at_all_is_refused_rather_than_trusted(): void
    {
        $store = $this->store('salla', 'store_a');

        Http::fake([
            'api.salla.dev/*/store/info' => Http::response(['data' => []]),
            'api.salla.dev/*' => Http::response(['data' => [$this->sallaOrder()], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]),
        ]);

        $run = app(StoreSyncer::class)->sync($store, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-05'));

        $this->assertSame('failed', $run->status);
        $this->assertSame(0, CommerceOrder::withoutGlobalScopes()->count());
    }

    // ── fixture ────────────────────────────────────────────────────────────────────────────────────

    private function store(string $provider, string $externalId): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30), raw: ['authorization' => 'MANAGER']),
            connectionName: $provider,
        );

        $store = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => $provider,
            'account_type' => 'store', 'external_id' => $externalId, 'name' => 'Store', 'currency' => 'SAR', 'status' => 'active',
        ]);

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->project->client_workspace_id,
            'project_id' => $this->project->id, 'external_account_id' => $store->getKey(),
            'provider' => $provider, 'purpose' => 'ecommerce', 'is_active' => true,
        ]);

        return $store;
    }

    /** @return array<string,mixed> */
    private function sallaOrder(): array
    {
        return [
            'id' => 'o1', 'reference_id' => 5, 'status' => ['slug' => 'completed'],
            'date' => ['date' => '2026-08-01 10:00:00.000000', 'timezone' => 'Asia/Riyadh'],
            'amounts' => ['total' => ['amount' => 100.0, 'currency' => 'SAR']],
            'items' => [['id' => 'i1', 'name' => 'x', 'quantity' => 1, 'amounts' => ['total' => ['amount' => 100.0, 'currency' => 'SAR']]]],
        ];
    }
}
