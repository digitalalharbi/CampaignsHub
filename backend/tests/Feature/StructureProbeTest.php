<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * INTEG-ACCOUNT-CHOICE-001 — «does this ad account hold the business's campaigns?»
 *
 * ## The question the stored diagnosis cannot answer
 *
 * `act_1500383245036671` was bound to Project 1 and returned nothing, while three other discovered
 * Meta accounts sat unbound. `integrations:diagnose` could say nothing about those three — an account
 * that has never been bound has never been synced, so the database holds no answer about it at all.
 * Only the provider does, and nothing here was allowed to ask.
 *
 * The insights probe was the wrong instrument for it: it answers «what did this account spend in a
 * window», and an account silent for thirty days looks exactly like an empty one through a window
 * while holding four years of history behind it.
 *
 * `--structure` asks the campaigns edge and reports identity, counts, statuses and the date range.
 * Read-only, like the rest of this command: the campaigns come back, are counted, and are thrown
 * away. Nothing is imported and no binding is created — choosing an account is the owner's decision,
 * and this exists to inform it rather than to make it.
 */
final class StructureProbeTest extends TestCase
{
    use RefreshDatabase;

    private ExternalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PlatformCredentials::for('meta')->requires() as $key) {
            config()->set("ad_platforms.platforms.meta.{$key}", "test-{$key}");
        }

        $tenant = Tenant::create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        Project::create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active',
        ]);

        $credential = IntegrationCredential::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'provider' => 'meta',
            'credential_scope' => 'tenant', 'credential_type' => 'oauth',
            'encrypted_payload' => json_encode(['access_token' => 'tok']), 'status' => 'active',
        ]);
        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'credential_id' => $credential->id, 'provider' => 'meta',
            'connection_name' => 'Meta', 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'provider_connection_id' => $connection->id,
            'provider' => 'meta', 'account_type' => 'ad_account',
            'external_id' => 'act_374140991630974', 'name' => 'razzahavenu', 'status' => 'active',
            'currency' => 'SAR', 'timezone' => 'Asia/Riyadh',
        ]);
    }

    /** An account with history reports its counts, its statuses and how far back it goes. */
    public function test_it_reports_the_campaign_census_and_date_range(): void
    {
        Http::fake(['*' => Http::response(['data' => [
            ['id' => '1', 'name' => 'Ramadan Sales', 'status' => 'PAUSED', 'start_time' => '2025-03-01T00:00:00+0300', 'stop_time' => '2025-04-01T00:00:00+0300'],
            ['id' => '2', 'name' => 'Always-On', 'status' => 'ACTIVE', 'start_time' => '2026-08-01T00:00:00+0300', 'stop_time' => '2026-09-01T00:00:00+0300'],
        ]], 200)]);

        $this->artisan('integrations:probe', ['account' => 'act_374140991630974', '--structure' => true])
            ->expectsOutputToContain('STRUCTURE PROBE — nothing is stored by this command.')
            ->expectsOutputToContain('campaigns returned : 2')
            ->expectsOutputToContain('earliest start     : 2025-03-01')
            ->expectsOutputToContain('latest end/updated : 2026-09-01')
            ->expectsOutputToContain('Ramadan Sales')
            ->assertSuccessful();
    }

    /**
     * An empty account says it is empty, in those words.
     *
     * «The provider answered and listed no campaigns» and «the request failed» are different findings
     * with different next steps, and the whole value of this probe is that it keeps them apart — the
     * bound Meta account's `no_data` was the first of those and read for weeks like the second.
     */
    public function test_an_empty_account_is_named_as_empty_rather_than_as_a_failure(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $this->artisan('integrations:probe', ['account' => 'act_374140991630974', '--structure' => true])
            ->expectsOutputToContain('campaigns returned : 0')
            ->expectsOutputToContain('This account is empty')
            ->assertSuccessful();
    }

    /** And it stores nothing — the whole premise of a probe. */
    public function test_the_probe_imports_nothing(): void
    {
        Http::fake(['*' => Http::response(['data' => [
            ['id' => '1', 'name' => 'Ramadan Sales', 'status' => 'PAUSED', 'start_time' => '2025-03-01T00:00:00+0300'],
        ]], 200)]);

        $this->artisan('integrations:probe', ['account' => 'act_374140991630974', '--structure' => true])->assertSuccessful();

        $this->assertDatabaseCount('external_campaigns', 0);
    }
}
