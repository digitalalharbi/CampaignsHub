<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * DISCOVERY-REFRESH-001 — the refresh button, run by an operator: a dry run stores nothing, an
 * applied run stores what the provider lists (the business-reached account included), ids only.
 */
final class DiscoveryRefreshCommandTest extends TestCase
{
    use RefreshDatabase;

    private ProviderConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['name' => 'Ads', 'slug' => 'ads-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        foreach (PlatformCredentials::for('meta')->requires() as $key) {
            config()->set("ad_platforms.platforms.meta.{$key}", "test-{$key}");
        }

        $this->connection = app(TokenVault::class)->open(
            tenantId: $tenant->id,
            provider: 'meta',
            tokens: new OAuthTokens('AT-secret', 'RT', Carbon::now()->addDay()),
            connectionName: 'meta',
        );

        $f = ['currency' => 'SAR', 'timezone_name' => 'Asia/Riyadh', 'account_status' => 1];
        Http::fake([
            'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => [['id' => 'act_1', 'name' => 'Direct'] + $f]]),
            'graph.facebook.com/*/me/businesses*' => Http::response(['data' => [['id' => 'b1', 'name' => 'Agency BM']]]),
            'graph.facebook.com/*/b1/owned_ad_accounts*' => Http::response(['data' => []]),
            'graph.facebook.com/*/b1/client_ad_accounts*' => Http::response(['data' => [['id' => 'act_628', 'name' => 'Henka'] + $f]]),
        ]);
    }

    private function stored(): array
    {
        return ExternalAccount::withoutGlobalScopes()
            ->where('provider_connection_id', $this->connection->getKey())
            ->pluck('external_id')->sort()->values()->all();
    }

    public function test_a_dry_run_names_what_would_be_added_and_stores_nothing(): void
    {
        $this->artisan('integrations:discovery-refresh', ['--connection' => $this->connection->getKey()])
            ->expectsOutputToContain('would be added: 2 — act_1, act_628')
            ->doesntExpectOutputToContain('Henka')
            ->assertSuccessful();

        $this->assertSame([], $this->stored());
    }

    public function test_applied_it_stores_the_business_reached_account_as_the_button_does(): void
    {
        $this->artisan('integrations:discovery-refresh', ['--connection' => $this->connection->getKey(), '--apply' => true])
            ->expectsOutputToContain('created 2')
            ->doesntExpectOutputToContain('Agency BM')
            ->assertSuccessful();

        $this->assertSame(['act_1', 'act_628'], $this->stored());
        $this->assertNotNull($this->connection->fresh()->last_discovery_attempted_at);
    }

    public function test_by_provider_it_refreshes_every_connected_connection(): void
    {
        $this->artisan('integrations:discovery-refresh', ['--provider' => 'meta', '--apply' => true])
            ->expectsOutputToContain((string) $this->connection->getKey())
            ->assertSuccessful();

        $this->assertSame(['act_1', 'act_628'], $this->stored());
    }

    public function test_an_unknown_connection_is_refused(): void
    {
        $this->artisan('integrations:discovery-refresh', ['--connection' => '00000000-0000-0000-0000-000000000000'])
            ->assertFailed();
    }
}
