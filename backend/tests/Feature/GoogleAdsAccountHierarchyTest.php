<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GADS-VERSION-001 / GADS-HIERARCHY-001 / GADS-MCC-001 — Google Ads, audited against the current
 * sunset table, «Listing accessible customers», «Get the account hierarchy» and the `customer_client`
 * field reference.
 *
 * Three defects, and the first of them means the other two had never had a chance to matter: while
 * the version is sunset, no Google Ads call succeeds at all.
 *
 * ## GADS-VERSION-001 — v18 is not merely old, it is gone
 *
 * Google's sunset table lists the **released** versions as v21 through v25. v18 is not on it. Google
 * states the consequence plainly: a sunset version can no longer be used, and requests to it **fail**
 * on or after the sunset date. This is the opposite of Meta's behaviour (META-VERSION-001), which
 * silently falls back — here every single call is refused, for every customer, always.
 *
 * ## GADS-HIERARCHY-001 — `listAccessibleCustomers` is not a list of ad accounts
 *
 * The connector treated every resource name it returned as an ad account. Google's own page says
 * what it actually returns: the accounts **the authenticated user can act on directly** — which
 * includes MANAGER accounts, and excludes the customers underneath them. Its worked example is
 * exactly this: user A with admin rights on manager M1 and account C3 can reach M1, C1, C2 and C3,
 * but `ListAccessibleCustomers` returns **only M1 and C3**.
 *
 * So for the ordinary agency shape — one manager account over the clients' accounts — we discovered
 * the MANAGER as though it were an ad account, and the accounts that actually hold the campaigns and
 * the spend were never discovered at all. A manager holds no campaigns, so the one "account" we did
 * create would sync cleanly and report nothing, forever, which reads as a quiet month rather than as
 * a broken integration.
 *
 * The documented answer is `customer_client`, which returns every direct and indirect client of a
 * manager — plus the manager itself at `level = 0` — with the client's name, currency, timezone and
 * whether it is itself a manager.
 *
 * ## GADS-MCC-001 — the manager account id was a SYSTEM credential
 *
 * `login_customer_id` sat in the platform's own `/admin` configuration and was sent as
 * `login-customer-id` on every call, for every tenant. Google documents it as the manager account
 * through which **the caller reaches that particular client account** — so it belongs to the
 * customer's hierarchy, not to this platform. One value in one system row was wrong for every tenant
 * but at most one. It was also stamped into `parent_external_id` on every discovered account, making
 * one operator's MCC id the recorded parent of every client's accounts.
 *
 * This is the same defect as SNAP-ORG-001, in the same place, for the same reason.
 */
final class GoogleAdsAccountHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'G', 'slug' => 'g-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    // ── The version ───────────────────────────────────────────────────────────────────────────

    /**
     * GADS-VERSION-001.
     *
     * Google Ads API sunset table: v21 (released 6 Aug 2025, sunset Aug 2026), v22 (15 Oct 2025 →
     * Oct 2026), v23 (28 Jan 2026 → Feb 2027), v24 (22 Apr 2026 → May 2027), v25 (22 Jul 2026 →
     * Aug 2027). Asserted as a NUMBER with a floor so an upgrade passes and only standing still fails.
     */
    public function test_google_is_not_pinned_to_a_sunset_api_version(): void
    {
        $base = (string) config('ad_platforms.platforms.google.api_base');

        $this->assertSame(1, preg_match('#/v(\d+)(\.\d+)?$#', $base, $m), 'the api base must pin a version');

        $this->assertGreaterThanOrEqual(
            25,
            (int) $m[1],
            "Google Ads is pinned to v{$m[1]}. Google's released-versions table starts at v21, and a "
                .'sunset version does not degrade — requests to it FAIL. Every call would be refused.',
        );
    }

    // ── Discovery ─────────────────────────────────────────────────────────────────────────────

    /**
     * The heart of GADS-HIERARCHY-001: the manager is not an ad account, and its clients are.
     *
     * The fixture is Google's own worked example. `listAccessibleCustomers` answers with the manager
     * M1 and the directly-held account C3; the hierarchy under M1 holds C1 and C2.
     */
    public function test_the_clients_under_a_manager_are_discovered_and_the_manager_itself_is_not(): void
    {
        $this->fakeHierarchy();

        $accounts = $this->bound('google')->listAdAccounts();
        $ids = array_column($accounts, 'external_id');

        sort($ids);

        $this->assertSame(
            ['1111111111', '2222222222', '3333333333'],
            $ids,
            'GADS-HIERARCHY-001: only the entry points were discovered, so the manager was recorded as '
                .'an ad account and the accounts holding the campaigns were never discovered at all.',
        );

        $this->assertNotContains(
            '9999999999',
            $ids,
            'a manager account holds no campaigns; recording it as an ad account produces a source that '
                .'syncs cleanly and reports nothing forever',
        );
    }

    /** The manager is read from the customer's OWN hierarchy — never from platform configuration. */
    public function test_the_parent_of_each_account_is_the_customers_own_manager(): void
    {
        // A system value is deliberately present, and must be ignored rather than used.
        config()->set('ad_platforms.platforms.google.login_customer_id', '8888888888');
        $this->fakeHierarchy();

        $accounts = collect($this->bound('google')->listAdAccounts())->keyBy('external_id');

        $this->assertSame('9999999999', $accounts['1111111111']['parent_external_id'], 'C1 sits under M1');
        $this->assertSame('9999999999', $accounts['2222222222']['parent_external_id'], 'C2 sits under M1');
        $this->assertNull($accounts['3333333333']['parent_external_id'], 'C3 is held directly, not through a manager');

        foreach ($accounts as $account) {
            $this->assertNotSame(
                '8888888888',
                $account['parent_external_id'],
                'GADS-MCC-001: the platform operator\'s own manager id was stamped as the parent of '
                    .'every tenant\'s accounts',
            );
        }
    }

    /** `customer_client` carries the name, currency and timezone, so none of them stays null. */
    public function test_the_account_carries_the_name_currency_and_timezone_the_hierarchy_reports(): void
    {
        $this->fakeHierarchy();

        $accounts = collect($this->bound('google')->listAdAccounts())->keyBy('external_id');

        $this->assertSame('Client One', $accounts['1111111111']['name']);
        $this->assertSame('SAR', $accounts['1111111111']['currency']);
        $this->assertSame('Asia/Riyadh', $accounts['1111111111']['timezone']);
    }

    /**
     * A cancelled client is not an active one.
     *
     * `customer_client.status` is reported per client, and treating every discovered row as `active`
     * — which the old code did unconditionally — puts a live badge on an account that has been closed.
     */
    public function test_a_client_that_google_reports_as_cancelled_is_not_recorded_as_active(): void
    {
        $this->fakeHierarchy();

        $accounts = collect($this->bound('google')->listAdAccounts())->keyBy('external_id');

        $this->assertSame('active', $accounts['1111111111']['status']);
        $this->assertSame('inactive', $accounts['2222222222']['status'], 'C2 is CANCELED in the fixture');
    }

    // ── The header ────────────────────────────────────────────────────────────────────────────

    /**
     * GADS-MCC-001 — `login-customer-id` on a per-customer query is the manager THAT customer is
     * reached through, taken from the account we discovered, not from configuration.
     */
    public function test_a_query_for_a_managed_customer_is_sent_through_that_customers_own_manager(): void
    {
        config()->set('ad_platforms.platforms.google.login_customer_id', '8888888888');
        $this->configure('google');

        $connection = $this->connection('google');
        $this->account($connection, '1111111111', parent: '9999999999');

        Http::fake(['googleads.googleapis.com/*' => Http::response([])]);

        $this->registry('google', $connection)->syncInsights('111-111-1111', '2026-08-01', '2026-08-02');

        Http::assertSent(function (Request $request): bool {
            $this->assertSame(
                '9999999999',
                $request->header('login-customer-id')[0] ?? null,
                'the query must be made through the manager this customer is actually reached through',
            );

            return true;
        });
    }

    /** An account held directly sends no manager header at all — Google defaults it to the customer. */
    public function test_a_directly_held_customer_is_queried_without_a_manager_header(): void
    {
        config()->set('ad_platforms.platforms.google.login_customer_id', '8888888888');
        $this->configure('google');

        $connection = $this->connection('google');
        $this->account($connection, '3333333333', parent: null);

        Http::fake(['googleads.googleapis.com/*' => Http::response([])]);

        $this->registry('google', $connection)->syncInsights('333-333-3333', '2026-08-01', '2026-08-02');

        Http::assertSent(function (Request $request): bool {
            $this->assertSame(
                [],
                $request->header('login-customer-id'),
                'GADS-MCC-001: the operator\'s own manager id was sent on every tenant\'s calls',
            );

            return true;
        });
    }

    /** The developer token stays a system credential — it identifies US, and is sent on every call. */
    public function test_the_developer_token_is_still_sent_on_every_call(): void
    {
        $this->configure('google');
        $connection = $this->connection('google');
        Http::fake(['googleads.googleapis.com/*' => Http::response([])]);

        $this->registry('google', $connection)->syncInsights('333-333-3333', '2026-08-01', '2026-08-02');

        Http::assertSent(fn (Request $r) => ($r->header('developer-token')[0] ?? null) === 'test-developer_token');
    }

    // ── The catalogue ─────────────────────────────────────────────────────────────────────────

    /**
     * And the operator is no longer ASKED for it.
     *
     * A field on the `/admin` form is an instruction. Leaving «معرّف الحساب المدير» there after the
     * value stopped being used would be worse than the defect: it invites an operator to paste a
     * customer's manager id into a platform-wide setting and believe they have configured something.
     */
    public function test_the_admin_form_no_longer_asks_for_a_manager_account_id(): void
    {
        $fields = array_map(
            static fn ($field): string => $field->key,
            ProviderCatalogue::get('google')->fields,
        );

        $this->assertSame(['client_id', 'client_secret', 'developer_token'], $fields);
        $this->assertNotContains('login_customer_id', $fields);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    /**
     * Google's own worked example, as HTTP.
     *
     * `listAccessibleCustomers` returns manager M1 (9999999999) and directly-held C3 (3333333333).
     * The hierarchy query under M1 returns M1 itself at level 0 plus C1 and C2; under C3 it returns
     * only C3's self link, which is what a non-manager answers.
     */
    private function fakeHierarchy(): void
    {
        $this->configure('google');

        Http::fake([
            'googleads.googleapis.com/*customers:listAccessibleCustomers' => Http::response([
                'resourceNames' => ['customers/9999999999', 'customers/3333333333'],
            ]),
            'googleads.googleapis.com/*customers/9999999999/googleAds:searchStream' => Http::response([[
                'results' => [
                    ['customerClient' => [
                        'id' => '9999999999', 'descriptiveName' => 'Agency MCC', 'manager' => true,
                        'level' => '0', 'status' => 'ENABLED', 'currencyCode' => 'SAR', 'timeZone' => 'Asia/Riyadh',
                    ]],
                    ['customerClient' => [
                        'id' => '1111111111', 'descriptiveName' => 'Client One', 'manager' => false,
                        'level' => '1', 'status' => 'ENABLED', 'currencyCode' => 'SAR', 'timeZone' => 'Asia/Riyadh',
                    ]],
                    ['customerClient' => [
                        'id' => '2222222222', 'descriptiveName' => 'Client Two', 'manager' => false,
                        'level' => '1', 'status' => 'CANCELED', 'currencyCode' => 'AED', 'timeZone' => 'Asia/Dubai',
                    ]],
                ],
            ]]),
            'googleads.googleapis.com/*customers/3333333333/googleAds:searchStream' => Http::response([[
                'results' => [
                    ['customerClient' => [
                        'id' => '3333333333', 'descriptiveName' => 'Direct Account', 'manager' => false,
                        'level' => '0', 'status' => 'ENABLED', 'currencyCode' => 'SAR', 'timeZone' => 'Asia/Riyadh',
                    ]],
                ],
            ]]),
        ]);
    }

    private function configure(string $platform): void
    {
        foreach (PlatformCredentials::for($platform)->requires() as $key) {
            config()->set("ad_platforms.platforms.{$platform}.{$key}", "test-{$key}");
        }
    }

    private function connection(string $provider): ProviderConnection
    {
        return app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: $provider,
            tokens: new OAuthTokens('AT-secret', 'RT', Carbon::now()->addDay()),
            connectionName: $provider,
        );
    }

    private function account(ProviderConnection $connection, string $externalId, ?string $parent): void
    {
        ExternalAccount::create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => $connection->provider, 'account_type' => 'ad_account',
            'external_id' => $externalId, 'name' => $externalId, 'status' => 'active',
            'parent_external_id' => $parent,
        ]);
    }

    private function bound(string $platform): ApiAdvertisingConnector
    {
        return $this->registry($platform, $this->connection($platform));
    }

    private function registry(string $platform, ProviderConnection $connection): ApiAdvertisingConnector
    {
        return app(AdvertisingConnectorRegistry::class)->get($platform)->withConnection($connection);
    }
}
