<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GADS-ROOT-TYPE-001 — a root must be asked WHAT IT IS before it is asked what is under it.
 *
 * ## The owner's production evidence, after Explorer was granted
 *
 * Google Ads API enabled, Cloud project at **Explorer** — which does reach production — OAuth
 * re-authorised afterwards, `ListAccessibleCustomers` succeeding, and account discovery still failing
 * with `searchStream → 403 PERMISSION_DENIED`. So the access level is no longer the explanation, and
 * the earlier «Explorer under review» design is void: shipping it now would state something false.
 *
 * ## What the current code does, and why the one call that works is the tell
 *
 * `listAdAccounts()` takes every root from `ListAccessibleCustomers` and calls `clientsUnder($root)`
 * unconditionally — which sets `login-customer-id` to that root and queries `FROM customer_client` on
 * it. The type of the root is never established. Its docblock asserts «a plain account answers with
 * its own self link», and that assumption is the defect rather than a description of it.
 *
 * Google's own contract, checked rather than remembered:
 *
 *   - `ListAccessibleCustomers` «does not require a customer ID and IGNORES any supplied
 *     login-customer-id» — which is exactly why it is the only call in the flow that still succeeds,
 *     and why its success proves nothing about the login context of the calls after it;
 *   - the account hierarchy «of a MANAGER account» is what `customer_client` answers;
 *   - for an individual client account reached directly, «omit the login-customer-id header or set it
 *     to the same value as CUSTOMER_ID»;
 *   - «if making API calls with a manager account, you MUST specify a login-customer-id header».
 *
 * So a direct advertiser needs `FROM customer` on itself, and a manager needs `customer_client` with
 * `login-customer-id` set to that manager. One query for both cases cannot be right for both.
 *
 * ## What these cases pin
 *
 * The REQUESTS, not only the rows. A discovery that returns the right accounts by accident — because a
 * fake answered a query it should never have been sent — would pass a row-only assertion and fail in
 * production, which is the situation this row exists to end.
 */
final class GoogleAdsRootTypeDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'G', 'slug' => 'g-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());

        /*
         * Every credential key the catalogue declares, so these cases test DISCOVERY.
         *
         * `listAdAccounts()` answers `[]` when `status()` is not Connected, and readiness currently
         * counts `developer_token` among the required secrets. A first version of this file set only the
         * OAuth pair and every case failed with «did not become a selectable account» — a readiness gate
         * reported as a discovery defect. Whether that requirement is still correct after Google's
         * 2026-09-09 token sunset is a SEPARATE question, and it is asked in its own guard rather than
         * settled by a fixture here.
         */
        foreach (['client_id', 'client_secret', 'developer_token'] as $key) {
            config()->set("ad_platforms.platforms.google.{$key}", $key === 'client_id'
                ? '123456789012-abc.apps.googleusercontent.com'
                : "test-{$key}");
        }
    }

    private function connector(): ApiAdvertisingConnector
    {
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->getKey(),
            provider: 'google',
            tokens: new OAuthTokens('AT-secret', 'RT', Carbon::now()->addDay()),
            connectionName: 'google',
        );

        return app(AdvertisingConnectorRegistry::class)->get('google')->withConnection($connection);
    }

    /** One `customer` row, as `FROM customer` answers it. */
    private function customerRow(string $id, bool $manager, string $name): array
    {
        return [['results' => [[
            'customer' => [
                'id' => $id,
                'descriptiveName' => $name,
                'currencyCode' => 'SAR',
                'timeZone' => 'Asia/Riyadh',
                'manager' => $manager,
            ],
        ]]]];
    }

    /** The `login-customer-id` actually sent for a given customer path, or null when omitted. */
    private function loginHeaderFor(string $customerId): ?string
    {
        foreach (Http::recorded() as [$request, $_response]) {
            /** @var Request $request */
            if (str_contains($request->url(), "customers/{$customerId}/googleAds:searchStream")) {
                $sent = $request->header('login-customer-id');

                return $sent === [] ? null : (string) ($sent[0] ?? null);
            }
        }

        return null;
    }

    private function pathWasQueried(string $needle): bool
    {
        foreach (Http::recorded() as [$request, $_response]) {
            /** @var Request $request */
            if (str_contains($request->url(), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function queriedResource(string $customerId): ?string
    {
        foreach (Http::recorded() as [$request, $_response]) {
            /** @var Request $request */
            if (str_contains($request->url(), "customers/{$customerId}/googleAds:searchStream")) {
                $body = (string) ($request->data()['query'] ?? '');

                return str_contains($body, 'FROM customer_client') ? 'customer_client' : 'customer';
            }
        }

        return null;
    }

    #[Test]
    public function a_direct_advertiser_root_is_asked_about_itself_and_becomes_selectable(): void
    {
        Http::fake([
            'googleads.googleapis.com/*customers:listAccessibleCustomers' => Http::response([
                'resourceNames' => ['customers/3333333333'],
            ]),
            'googleads.googleapis.com/*customers/3333333333/googleAds:searchStream' => Http::response(
                $this->customerRow('3333333333', false, 'Direct advertiser'),
            ),
        ]);

        $accounts = $this->connector()->listAdAccounts();

        $this->assertCount(1, $accounts, 'a directly held advertiser did not become a selectable account');
        $this->assertSame('3333333333', $accounts[0]['external_id']);
        $this->assertSame('Direct advertiser', $accounts[0]['name']);
        $this->assertNull($accounts[0]['parent_external_id'], 'a directly held account was given a manager it does not sit under');

        $this->assertSame(
            'customer',
            $this->queriedResource('3333333333'),
            '`customer_client` was queried on an account that is not a manager — the resource Google documents for manager hierarchies',
        );
    }

    /** «Omit the header, or set it to the same value as CUSTOMER_ID» — both permitted, nothing else. */
    #[Test]
    public function a_direct_advertiser_is_queried_with_a_permitted_login_context(): void
    {
        Http::fake([
            'googleads.googleapis.com/*customers:listAccessibleCustomers' => Http::response([
                'resourceNames' => ['customers/3333333333'],
            ]),
            'googleads.googleapis.com/*customers/3333333333/googleAds:searchStream' => Http::response(
                $this->customerRow('3333333333', false, 'Direct advertiser'),
            ),
        ]);

        $this->connector()->listAdAccounts();

        $sent = $this->loginHeaderFor('3333333333');

        $this->assertTrue(
            $sent === null || $sent === '3333333333',
            "login-customer-id «{$sent}» is neither omitted nor the customer's own id",
        );
    }

    #[Test]
    public function a_manager_root_is_asked_for_its_hierarchy_through_itself(): void
    {
        Http::fake([
            'googleads.googleapis.com/*customers:listAccessibleCustomers' => Http::response([
                'resourceNames' => ['customers/9999999999'],
            ]),
            'googleads.googleapis.com/*customers/9999999999/googleAds:searchStream' => Http::sequence()
                ->push($this->customerRow('9999999999', true, 'The manager'))
                ->push([['results' => [
                    ['customerClient' => [
                        'id' => '1111111111', 'descriptiveName' => 'Child one', 'currencyCode' => 'SAR',
                        'timeZone' => 'Asia/Riyadh', 'manager' => false, 'status' => 'ENABLED', 'level' => '1',
                    ]],
                    ['customerClient' => [
                        'id' => '9999999999', 'descriptiveName' => 'The manager', 'currencyCode' => 'SAR',
                        'timeZone' => 'Asia/Riyadh', 'manager' => true, 'status' => 'ENABLED', 'level' => '0',
                    ]],
                ]]]),
        ]);

        $accounts = $this->connector()->listAdAccounts();

        $ids = array_column($accounts, 'external_id');

        $this->assertSame(['1111111111'], $ids, 'the manager itself was recorded as an advertiser, or its child was lost');
        $this->assertSame(
            '9999999999',
            $accounts[0]['parent_external_id'],
            'the real manager was not persisted, so later campaign queries cannot reuse the path that worked',
        );
        $this->assertSame(
            '9999999999',
            $this->loginHeaderFor('9999999999'),
            'a manager hierarchy was queried without login-customer-id set to that manager',
        );
    }

    /** A mixture is the ordinary case for an agency, and each root keeps its own contract. */
    #[Test]
    public function mixed_roots_are_each_handled_by_their_own_type(): void
    {
        Http::fake([
            'googleads.googleapis.com/*customers:listAccessibleCustomers' => Http::response([
                'resourceNames' => ['customers/9999999999', 'customers/3333333333'],
            ]),
            'googleads.googleapis.com/*customers/9999999999/googleAds:searchStream' => Http::sequence()
                ->push($this->customerRow('9999999999', true, 'The manager'))
                ->push([['results' => [['customerClient' => [
                    'id' => '1111111111', 'descriptiveName' => 'Child one', 'currencyCode' => 'SAR',
                    'timeZone' => 'Asia/Riyadh', 'manager' => false, 'status' => 'ENABLED', 'level' => '1',
                ]]]]]),
            'googleads.googleapis.com/*customers/3333333333/googleAds:searchStream' => Http::response(
                $this->customerRow('3333333333', false, 'Direct advertiser'),
            ),
        ]);

        $accounts = $this->connector()->listAdAccounts();
        $ids = array_column($accounts, 'external_id');

        sort($ids);
        $this->assertSame(['1111111111', '3333333333'], $ids);
        $this->assertSame('customer', $this->queriedResource('3333333333'), 'the direct advertiser was treated as a manager');
    }

    /** Reachable twice is still one account, and it keeps the manager it was actually reached through. */
    #[Test]
    public function an_account_reachable_directly_and_under_a_manager_is_recorded_once(): void
    {
        Http::fake([
            'googleads.googleapis.com/*customers:listAccessibleCustomers' => Http::response([
                'resourceNames' => ['customers/9999999999', 'customers/1111111111'],
            ]),
            'googleads.googleapis.com/*customers/9999999999/googleAds:searchStream' => Http::sequence()
                ->push($this->customerRow('9999999999', true, 'The manager'))
                ->push([['results' => [['customerClient' => [
                    'id' => '1111111111', 'descriptiveName' => 'Child one', 'currencyCode' => 'SAR',
                    'timeZone' => 'Asia/Riyadh', 'manager' => false, 'status' => 'ENABLED', 'level' => '1',
                ]]]]]),
            'googleads.googleapis.com/*customers/1111111111/googleAds:searchStream' => Http::response(
                $this->customerRow('1111111111', false, 'Child one'),
            ),
        ]);

        $accounts = $this->connector()->listAdAccounts();

        $this->assertCount(1, $accounts, 'one advertiser reachable two ways was recorded twice');
        $this->assertSame('1111111111', $accounts[0]['external_id']);
    }

    /**
     * A refusal keeps the facts an operator needs, instead of collapsing to «HTTP 403».
     *
     * The classification and the request-id are what separate «this identity cannot see this customer»
     * from «the login context was wrong» — two different next actions, and the generic message sent
     * the owner to look at neither.
     */
    #[Test]
    public function a_refusal_carries_the_google_classification_and_request_id(): void
    {
        Http::fake([
            'googleads.googleapis.com/*customers:listAccessibleCustomers' => Http::response([
                'resourceNames' => ['customers/3333333333'],
            ]),
            'googleads.googleapis.com/*googleAds:searchStream' => Http::response([
                'error' => [
                    'code' => 403,
                    'message' => 'User doesn\'t have permission to access customer.',
                    'status' => 'PERMISSION_DENIED',
                    'details' => [[
                        '@type' => 'type.googleapis.com/google.ads.googleads.v25.errors.GoogleAdsFailure',
                        'errors' => [[
                            'errorCode' => ['authorizationError' => 'USER_PERMISSION_DENIED'],
                            'message' => 'User doesn\'t have permission to access customer.',
                        ]],
                        'requestId' => 'REQ-abc-123',
                    ]],
                ],
            ], 403),
        ]);

        try {
            $this->connector()->listAdAccounts();
            $this->fail('a refused discovery returned normally');
        } catch (\Throwable $e) {
            $said = $e->getMessage();

            $this->assertStringContainsString('USER_PERMISSION_DENIED', $said, 'the Google classification was discarded');
            $this->assertStringContainsString('REQ-abc-123', $said, 'the request id was discarded, so Google cannot be asked about it');
            $this->assertStringContainsString('3333333333', $said, 'the operating customer was not named');
        }
    }
}
