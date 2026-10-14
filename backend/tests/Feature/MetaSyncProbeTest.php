<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * META-SYNC-PROOF-001 — «the OAuth app is valid» is not «the accounts are syncing».
 *
 * The platform-admin page proves a Meta app's configuration and says nothing about a CONNECTED
 * account, because the two fail for different reasons: the app can be perfectly configured while the
 * account owner has revoked `ads_read`, the token has expired, or Business Manager access has been
 * withdrawn. Those are three different conversations with three different people, and the product
 * collapsed all of them into one human sentence.
 *
 * Meta answers a refusal with `code`, `error_subcode`, `type`, `message` and an `fbtrace_id`, and
 * nothing in this codebase read any of them. The subcode is usually the only thing separating
 * «expired» from «revoked», and the trace id is the reference Meta's own support asks for.
 */
final class MetaSyncProbeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'M', 'slug' => 'mp-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        foreach (['client_id' => 'app-123', 'client_secret' => 'shh'] as $key => $value) {
            config()->set("ad_platforms.platforms.meta.{$key}", $value);
        }
    }

    /** With no client configured nothing here can reach Meta, and the probe says so instead of guessing. */
    public function test_it_refuses_to_judge_an_install_with_no_meta_client(): void
    {
        config()->set('ad_platforms.platforms.meta.client_id', null);

        $this->artisan('integrations:meta-probe')
            ->expectsOutputToContain('BLOCKED_EXTERNAL_CREDENTIALS')
            ->assertFailed();
    }

    /**
     * The historical failure, still the most likely one: the person granted the app but not `ads_read`.
     *
     * `(#200) Ad account owner has NOT grant ads_management or ads_read permission` is what this
     * install saw before, and a probe that only checked «does the token work» would call this healthy.
     */
    public function test_a_token_that_works_without_ads_read_is_permission_revoked(): void
    {
        $this->connection();
        Http::fake([
            '*/me/permissions' => Http::response(['data' => [
                ['permission' => 'public_profile', 'status' => 'granted'],
                ['permission' => 'ads_read', 'status' => 'declined'],
            ]]),
        ]);

        $this->artisan('integrations:meta-probe')
            ->expectsOutputToContain('NOT GRANTED')
            ->expectsOutputToContain('PERMISSION_REVOKED')
            ->assertSuccessful();
    }

    /** Meta's own five fields are printed, because they are what its support asks for. */
    public function test_it_prints_the_code_subcode_type_and_trace_id(): void
    {
        $this->connection();
        Http::fake([
            '*/me/permissions' => Http::response([
                'error' => [
                    'message' => 'Error validating access token: Session has expired',
                    'type' => 'OAuthException',
                    'code' => 190,
                    'error_subcode' => 463,
                    'fbtrace_id' => 'AbCdEf123456',
                ],
            ], 401),
        ]);

        $this->artisan('integrations:meta-probe')
            ->expectsOutputToContain('190')
            ->expectsOutputToContain('463')
            ->expectsOutputToContain('OAuthException')
            ->expectsOutputToContain('AbCdEf123456')
            ->expectsOutputToContain('TOKEN_REVOKED')
            ->assertSuccessful();
    }

    /**
     * A subcode that means «the person's session», classified as the person's session.
     *
     * Code 190 with subcode 458 is «app not installed» — the token, not the app's configuration.
     * Reporting it as APP_RESTRICTED would send somebody to the wrong screen entirely.
     */
    public function test_an_app_not_installed_subcode_is_still_the_token(): void
    {
        $this->connection();
        Http::fake([
            '*/me/permissions' => Http::response([
                'error' => ['message' => 'App not installed', 'type' => 'OAuthException', 'code' => 190, 'error_subcode' => 458, 'fbtrace_id' => 'Zz9'],
            ], 400),
        ]);

        $this->artisan('integrations:meta-probe')->expectsOutputToContain('TOKEN_REVOKED')->assertSuccessful();
    }

    /** A healthy account answers, and the probe reports what it holds rather than declaring a sync. */
    public function test_a_healthy_account_is_reported_as_answering(): void
    {
        $this->connection();
        Http::fake([
            '*/me/permissions' => Http::response(['data' => [
                ['permission' => 'ads_read', 'status' => 'granted'],
                ['permission' => 'ads_management', 'status' => 'granted'],
            ]]),
            '*/me/adaccounts*' => Http::response(['data' => [
                ['account_id' => '1234567890', 'name' => 'Acme Ads', 'account_status' => 1],
            ]]),
        ]);

        $this->artisan('integrations:meta-probe')
            ->expectsOutputToContain('GRANTED')
            ->expectsOutputToContain('1234567890')
            ->expectsOutputToContain('1 account(s)')
            ->assertSuccessful();
    }

    /**
     * META-PARTNER-ACCOUNT-DISCOVERY-001 — an account a BUSINESS reaches that the person's edge does not list.
     *
     * Discovery reads `me/adaccounts` only, and that edge answers for the PERSON: the accounts they
     * hold a role on. An ad account another company shares with the Business Manager as a Partner is
     * reachable through `{business}/client_ad_accounts` and reaches the person's edge only once they
     * are assigned to it inside the business. The probe has to print that difference, by id — and the
     * business's NAME, which Meta sends on the same row, must never reach a workflow log.
     */
    public function test_it_names_an_account_a_business_reaches_that_the_person_does_not(): void
    {
        $this->connection();
        Http::fake([
            '*/me/permissions' => Http::response(['data' => [
                ['permission' => 'ads_read', 'status' => 'granted'],
                ['permission' => 'business_management', 'status' => 'granted'],
            ]]),
            '*/me/adaccounts*' => Http::response(['data' => [
                ['account_id' => '1234567890', 'name' => 'Acme Ads', 'account_status' => 1],
            ]]),
            '*/me/businesses*' => Http::response(['data' => [
                ['id' => '111222333444', 'name' => 'Hanka Company Secret'],
            ]]),
            '*/111222333444/owned_ad_accounts*' => Http::response(['data' => []]),
            '*/111222333444/client_ad_accounts*' => Http::response(['data' => [
                ['account_id' => '1234567890', 'name' => 'Acme Ads', 'account_status' => 1],
                ['account_id' => '628943503215859', 'name' => 'hanka', 'account_status' => 1],
            ]]),
        ]);

        $this->artisan('integrations:meta-probe')
            ->expectsOutputToContain('1 reachable by this person')
            ->expectsOutputToContain('client_ad_accounts: 2 account(s) — 1234567890, 628943503215859')
            ->expectsOutputToContain('in a business, NOT in me/adaccounts : 1 account(s) ← discovery cannot see these')
            ->expectsOutputToContain('628943503215859  via client_ad_accounts of business 111222333444 (account_status=1)')
            ->expectsOutputToContain('in me/adaccounts, NOT in a business : 0 account(s)')
            ->doesntExpectOutputToContain('Hanka Company Secret')
            ->doesntExpectOutputToContain('hanka')
            ->assertSuccessful();
    }

    /**
     * Without `business_management` the business edges cannot be asked, and the probe says so rather
     * than printing «0 businesses» — which would read as «this person belongs to none».
     */
    public function test_without_business_management_the_business_edges_are_not_askable(): void
    {
        $this->connection();
        Http::fake([
            '*/me/permissions' => Http::response(['data' => [
                ['permission' => 'ads_read', 'status' => 'granted'],
                ['permission' => 'business_management', 'status' => 'declined'],
            ]]),
            '*/me/adaccounts*' => Http::response(['data' => [
                ['account_id' => '1234567890', 'name' => 'Acme Ads', 'account_status' => 1],
            ]]),
        ]);

        $this->artisan('integrations:meta-probe')
            ->expectsOutputToContain('NOT ASKABLE')
            ->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/me/businesses'));
    }

    /**
     * The person's edge is read to its end, as discovery reads it. A probe that stopped at one page
     * would report fewer accounts than discovery holds and send the reader after a paging defect
     * discovery does not have.
     */
    public function test_the_persons_edge_is_followed_to_its_last_page(): void
    {
        $this->connection();
        $base = (string) config('ad_platforms.platforms.meta.api_base');
        Http::fake([
            // Listed first: the SECOND page's address is the one Meta hands back in `paging.next`.
            '*/me/adaccounts?after=*' => Http::response(['data' => [
                ['account_id' => '2222222222', 'account_status' => 1],
            ]]),
            '*/me/adaccounts*' => Http::response([
                'data' => [['account_id' => '1111111111', 'account_status' => 1]],
                'paging' => ['next' => $base.'/me/adaccounts?after=CURSOR-NOT-PRINTED'],
            ]),
            '*/me/permissions' => Http::response(['data' => [
                ['permission' => 'ads_read', 'status' => 'granted'],
            ]]),
        ]);

        $this->artisan('integrations:meta-probe')
            ->expectsOutputToContain('2 account(s) via me/adaccounts, 2 page(s)')
            ->expectsOutputToContain('me/adaccounts : 1111111111, 2222222222')
            ->doesntExpectOutputToContain('CURSOR-NOT-PRINTED')
            ->assertSuccessful();
    }

    private function connection(): ProviderConnection
    {
        return app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'meta',
            tokens: new OAuthTokens('AT-secret', 'RT', Carbon::now()->addDay()),
            connectionName: 'Meta',
        );
    }
}
