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
use Tests\TestCase;

/**
 * GADS-HIERARCHY-001 §6 — the exact `GoogleAdsFailure`, shown to somebody.
 *
 * The connector assembles precisely what the owner asked for when a Google query is refused: the
 * failure member, the customer operated on, the login context it was reached through, and Google's
 * own request id — «authorizationError=USER_PERMISSION_DENIED | customer 123 | login-customer-id 456
 * | request abc». `AccountDiscovery` stores that whole sentence on the connection's `last_error`.
 *
 * `integrations:google-access` — the one command an operator runs to find out why Google is refusing
 * — printed `discovery_blocked_reason` instead. That is a CLASSIFICATION: `discovery_failed`,
 * `provider_project_not_approved`. It says which bucket the failure is in and nothing about which
 * customer, which login context or which member, which are the three facts that decide what to do
 * next. The careful sentence was computed and read by nobody, which is this codebase's most frequent
 * defect rather than a new one.
 */
final class GoogleAccessCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'G', 'slug' => 'g-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        // The command describes the configured client; without one it has nothing to report on.
        config()->set('ad_platforms.platforms.google.client_id', '1234567890-abcdefghijk.apps.googleusercontent.com');
    }

    public function test_it_prints_the_exact_google_failure_and_not_only_its_classification(): void
    {
        $connection = $this->connection();
        $failure = 'Google Ads refused the query | authorizationError=USER_PERMISSION_DENIED | '
            .'customer 9876543210 | login-customer-id 1112223334 | request Ab9CdEf';

        $connection->forceFill([
            'discovery_blocked_reason' => 'discovery_failed',
            'last_error' => $failure,
        ])->save();

        $this->artisan('integrations:google-access')
            ->expectsOutputToContain('authorizationError=USER_PERMISSION_DENIED')
            ->expectsOutputToContain('login-customer-id 1112223334')
            ->expectsOutputToContain('request Ab9CdEf')
            ->assertSuccessful();
    }

    /** The Cloud project is the answer to step 1, and the secret half of the id is never printed. */
    public function test_it_names_the_cloud_project_without_exposing_the_client(): void
    {
        $this->artisan('integrations:google-access')
            ->expectsOutputToContain('1234567890')
            ->doesntExpectOutputToContain('abcdefghijk')
            ->assertSuccessful();
    }

    /** A connection Google has never refused has nothing to quote, and the command stays quiet. */
    public function test_it_says_nothing_about_a_connection_that_never_failed(): void
    {
        $this->connection();

        $this->artisan('integrations:google-access')
            ->doesntExpectOutputToContain('what Google said')
            ->assertSuccessful();
    }

    private function connection(): ProviderConnection
    {
        return app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'google',
            tokens: new OAuthTokens('AT-secret', 'RT', Carbon::now()->addDay()),
            connectionName: 'google',
        );
    }
}
