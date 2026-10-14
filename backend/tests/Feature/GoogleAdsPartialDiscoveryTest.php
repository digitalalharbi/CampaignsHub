<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GADS-DISCOVERY-PARTIAL-001 — Production, 2026-10-12: `listAccessibleCustomers` answered and half
 * the per-customer `searchStream` reads were refused (403 PERMISSION_DENIED); the first refusal aborted
 * discovery and the connection showed 0 accounts behind a 502 «provider could not be reached».
 *
 * Each accessible customer is read on its own: the readable ones are catalogued, a refused one is a
 * warning carrying Google's own error code and request id, a closed manager is not queried, and a
 * refusal of everything is a 403 permission answer — never a 502.
 */
final class GoogleAdsPartialDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    private ProviderConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        foreach (['client_id', 'client_secret', 'developer_token'] as $key) {
            config()->set("ad_platforms.platforms.google.{$key}", $key === 'client_id' ? '123456789012-abc.apps.googleusercontent.com' : "test-{$key}");
        }

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $this->connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'google',
            tokens: new OAuthTokens('AT-secret', 'RT', Carbon::now()->addDay()),
            connectionName: 'google',
        );
        app(TenantContext::class)->forget();
    }

    /** @return array<int, array<string,mixed>> */
    private function customer(string $id, bool $manager, string $status = 'ENABLED'): array
    {
        return [['results' => [['customer' => ['id' => $id, 'descriptiveName' => "Customer {$id}", 'currencyCode' => 'SAR', 'timeZone' => 'Asia/Riyadh', 'manager' => $manager, 'status' => $status]]]]];
    }

    /** A searchStream refusal is a JSON ARRAY of chunks — the shape that hid the codes before. */
    private function refused(string $code = 'USER_PERMISSION_DENIED'): PromiseInterface
    {
        return Http::response([[
            'error' => [
                'code' => 403,
                'message' => 'The caller does not have permission',
                'status' => 'PERMISSION_DENIED',
                'details' => [[
                    '@type' => 'type.googleapis.com/google.ads.googleads.v25.errors.GoogleAdsFailure',
                    'errors' => [['errorCode' => ['authorizationError' => $code], 'message' => "User doesn't have permission to access customer."]],
                    'requestId' => 'req-abc-123',
                ]],
            ],
        ]], 403);
    }

    private function mixedAnswers(): void
    {
        Http::fake([
            'googleads.googleapis.com/*customers:listAccessibleCustomers' => Http::response(['resourceNames' => ['customers/1111111111', 'customers/2222222222', 'customers/3333333333', 'customers/6666666666']]),
            'googleads.googleapis.com/*customers/1111111111/googleAds:searchStream' => Http::response($this->customer('1111111111', false)),
            'googleads.googleapis.com/*customers/2222222222/googleAds:searchStream' => $this->refused(),
            'googleads.googleapis.com/*customers/6666666666/googleAds:searchStream' => Http::response($this->customer('6666666666', true, 'CANCELED')),
            'googleads.googleapis.com/*customers/3333333333/googleAds:searchStream' => function ($request) {
                $query = (string) ($request->data()['query'] ?? '');
                if (! str_contains($query, 'FROM customer_client')) {
                    return Http::response($this->customer('3333333333', true));
                }

                return Http::response([['results' => [
                    ['customerClient' => ['id' => '3333333333', 'descriptiveName' => 'Manager', 'manager' => true, 'level' => 0, 'status' => 'ENABLED']],
                    ['customerClient' => ['id' => '4444444444', 'descriptiveName' => 'Client open', 'manager' => false, 'level' => 1, 'status' => 'ENABLED', 'currencyCode' => 'SAR']],
                    ['customerClient' => ['id' => '5555555555', 'descriptiveName' => 'Client closed', 'manager' => false, 'level' => 1, 'status' => 'CANCELED', 'currencyCode' => 'SAR']],
                ]]]);
            },
        ]);
    }

    public function test_readable_customers_are_discovered_and_a_refused_one_is_a_warning_with_googles_codes(): void
    {
        $this->mixedAnswers();

        $res = $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/connections/{$this->connection->id}/refresh")
            ->assertOk();

        $this->assertSame(3, $res->json('data.discovered'), 'the direct advertiser and the manager\'s two clients');
        $this->assertSame(1, $res->json('data.inaccessible'));
        $warning = $res->json('data.warnings.0');
        $this->assertSame('2222222222', $warning['customer']);
        $this->assertStringContainsString('authorizationError=USER_PERMISSION_DENIED', $warning['reason']);
        $this->assertStringContainsString('request req-abc-123', $warning['reason'], 'the GoogleAdsFailure request id travels');

        $accounts = ExternalAccount::withoutGlobalScopes()->where('provider_connection_id', $this->connection->id)->pluck('status', 'external_id')->all();
        $this->assertSame(['1111111111' => 'active', '4444444444' => 'active', '5555555555' => 'inactive'], collect($accounts)->sortKeys()->all());
        $this->assertSame('3333333333', ExternalAccount::withoutGlobalScopes()->where('external_id', '4444444444')->value('parent_external_id'), 'a client is synced through the manager it sits under');

        // A closed manager is not asked for its clients, and a closed client is never queried.
        foreach (Http::recorded() as [$request]) {
            $this->assertFalse(str_contains($request->url(), 'customers/6666666666/googleAds') && str_contains((string) ($request->data()['query'] ?? ''), 'customer_client'), 'a cancelled manager was queried for clients');
            $this->assertStringNotContainsString('customers/5555555555/', $request->url(), 'a cancelled client was queried');
        }
    }

    public function test_a_partial_refresh_marks_no_account_as_lost(): void
    {
        ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $this->connection->id, 'provider' => 'google',
            'account_type' => 'ad_account', 'external_id' => '7777777777', 'name' => 'Under the refused customer', 'status' => 'active',
        ]);
        $this->mixedAnswers();

        $this->actingAs($this->operator, 'sanctum')->postJson("/api/v1/connections/{$this->connection->id}/refresh")->assertOk()->assertJsonPath('data.access_lost', 0);

        $this->assertNull(ExternalAccount::withoutGlobalScopes()->where('external_id', '7777777777')->value('access_lost_at'), 'a refused customer is not evidence its accounts were lost');
    }

    public function test_a_refusal_of_every_customer_is_a_permission_answer_not_an_unreachable_provider(): void
    {
        Http::fake([
            'googleads.googleapis.com/*customers:listAccessibleCustomers' => Http::response(['resourceNames' => ['customers/2222222222']]),
            'googleads.googleapis.com/*customers/2222222222/googleAds:searchStream' => $this->refused(),
        ]);

        $res = $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/connections/{$this->connection->id}/refresh")
            ->assertStatus(403);

        $this->assertSame('permission', $res->json('meta.category'));
        $this->assertStringContainsString('USER_PERMISSION_DENIED', (string) $res->json('meta.reason'));
        $this->assertStringContainsString('req-abc-123', (string) $res->json('meta.reason'));
    }
}
