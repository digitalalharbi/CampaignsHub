<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Enums\ProviderSetupState;
use App\Domains\Integrations\Models\ProviderConfiguration;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PROVCFG-001 — the platform operator's provider configuration.
 *
 * The tests that matter most here are the negative ones. Anybody can assert that a saved secret comes
 * back; the whole point of this layer is that it never does, that a tenant cannot reach it, and that a
 * configuration nobody has tested is never described as ready.
 */
final class PlatformProviderSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Platform', 'email' => 'p@platform.test', 'password' => 'secret123']);
        $this->owner->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-'.uniqid()]);
        $this->customer = User::create(['name' => 'Cust', 'email' => 'c@acme.test', 'password' => 'secret123']);
        $this->customer->forceFill(['email_verified_at' => now()])->save();
        $this->grantMembership($this->customer, $tenant);
    }

    // ── the boundary ──────────────────────────────────────────────────────────────────────────

    /** The one test this file exists for: a customer has no path to the system's keys, read or write. */
    public function test_a_tenant_user_cannot_read_or_write_provider_configuration(): void
    {
        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/admin/settings/integrations/providers')
            ->assertStatus(403);

        $this->actingAs($this->customer, 'sanctum')
            ->putJson('/api/v1/admin/settings/integrations/providers/meta', ['client_secret' => 'stolen'])
            ->assertStatus(403);

        $this->assertDatabaseCount('provider_configurations', 0);
    }

    public function test_an_unknown_provider_is_a_404_rather_than_an_empty_form(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/admin/settings/integrations/providers/myspace')
            ->assertStatus(404);
    }

    // ── the catalogue ─────────────────────────────────────────────────────────────────────────

    /**
     * Eight providers, in the product's order, each carrying its OWN field list.
     *
     * The assertion on Google and Snapchat is the point: a generic model would give every provider
     * `client_id` + `client_secret` and let both fail as "connected, and no data".
     */
    public function test_the_listing_describes_each_provider_with_its_own_requirements(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/admin/settings/integrations/providers')
            ->assertOk();

        $keys = array_column($response->json('data.providers'), 'key');
        $this->assertSame(['snapchat', 'tiktok', 'meta', 'google', 'x', 'linkedin', 'salla', 'zid'], $keys);

        $google = collect($response->json('data.providers'))->firstWhere('key', 'google');
        $this->assertContains('developer_token', array_column($google['fields'], 'key'));
        $this->assertTrue(collect($google['fields'])->firstWhere('key', 'developer_token')['required']);

        /*
         * Snapchat asks for the PLATFORM'S app and nothing else — SNAP-ORG-001.
         *
         * This used to assert that `organization_id` was a required system credential, which is the
         * defect rather than the requirement: one organisation id in one system row pointed every
         * tenant's token at the operator's organisation. Organisations and their ad accounts come
         * from `GET /me/organizations?with_ad_accounts=true`, scoped to whoever authorised.
         */
        $snapchat = collect($response->json('data.providers'))->firstWhere('key', 'snapchat');
        $this->assertSame(
            ['client_id', 'client_secret'],
            array_column($snapchat['fields'], 'key'),
            'nothing belonging to a customer may be asked for as a platform credential',
        );

        // X is the only one of the eight whose authorisation is refused outright without PKCE.
        $x = collect($response->json('data.providers'))->firstWhere('key', 'x');
        $this->assertTrue($x['uses_pkce']);
        $this->assertFalse($google['uses_pkce']);

        // Commerce providers are not advertising platforms and are not described as though they were.
        $salla = collect($response->json('data.providers'))->firstWhere('key', 'salla');
        $this->assertSame('commerce', $salla['kind']);
        $this->assertStringContainsString('/webhooks/commerce/salla', (string) $salla['webhook_url']);
    }

    /** A provider that cannot receive events is not given a webhook URL to register. */
    public function test_a_polling_only_provider_has_no_webhook_url(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/admin/settings/integrations/providers/google')
            ->assertOk();

        $this->assertSame('polling_only', $response->json('data.webhooks'));
        $this->assertNull($response->json('data.webhook_url'));
    }

    // ── secrets go in, and never come out ─────────────────────────────────────────────────────

    public function test_a_saved_secret_is_never_returned_by_any_endpoint(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/v1/admin/settings/integrations/providers/meta', [
                'client_id' => 'app-123456',
                'client_secret' => 'super-secret-value-9876',
            ])
            ->assertOk();

        foreach ([
            '/api/v1/admin/settings/integrations/providers',
            '/api/v1/admin/settings/integrations/providers/meta',
        ] as $url) {
            $body = $this->actingAs($this->owner, 'sanctum')->getJson($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('super-secret-value-9876', $body);
            $this->assertStringNotContainsString('app-123456', $body);
            // Four characters is enough to recognise a key and not enough to be one.
            $this->assertStringContainsString('9876', $body);
        }
    }

    public function test_the_stored_column_is_ciphertext_at_rest(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/v1/admin/settings/integrations/providers/meta', [
                'client_id' => 'app-1', 'client_secret' => 'plaintext-would-be-here',
            ])->assertOk();

        $raw = (string) \DB::table('provider_configurations')->where('provider', 'meta')->value('credentials');

        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('plaintext-would-be-here', $raw);
    }

    /** The audit trail records WHICH field changed, never what it changed to. */
    public function test_a_write_is_audited_by_field_name_and_never_by_value(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/v1/admin/settings/integrations/providers/tiktok', [
                'client_id' => 'app-id-1', 'client_secret' => 'a-secret-nobody-should-log',
            ])->assertOk();

        $entry = \DB::table('audit_logs')->where('action', 'platform.integration.provider.updated')->first();

        $this->assertNotNull($entry);
        $this->assertStringNotContainsString('a-secret-nobody-should-log', (string) $entry->after);
        $this->assertStringContainsString('client_secret', (string) $entry->after);
    }

    // ── partial writes ────────────────────────────────────────────────────────────────────────

    /**
     * An empty field means "leave it alone", not "set it to empty".
     *
     * This is what lets an operator change the environment without re-typing a secret they cannot
     * read back — and without it, every such edit would silently blank the credentials.
     */
    public function test_an_omitted_or_empty_field_leaves_the_stored_value_untouched(): void
    {
        $settings = app(ProviderConfigurationService::class);

        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/v1/admin/settings/integrations/providers/meta', [
                'client_id' => 'app-1', 'client_secret' => 'kept',
            ])->assertOk();

        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/v1/admin/settings/integrations/providers/meta', [
                'client_secret' => '', 'environment' => 'production',
            ])->assertOk();

        $settings->forgetCache();
        $this->assertSame('kept', $settings->value('meta', 'client_secret'));
        $this->assertSame('production', $settings->environment('meta'));
    }

    /** Clearing a credential is its own explicit call, and it is audited. */
    public function test_a_credential_is_cleared_only_by_an_explicit_delete(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/v1/admin/settings/integrations/providers/meta', [
                'client_id' => 'app-1', 'client_secret' => 'goes-away',
            ])->assertOk();

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson('/api/v1/admin/settings/integrations/providers/meta/credentials/client_secret')
            ->assertOk()
            ->assertJsonPath('data.state', ProviderSetupState::AwaitingCredentials->value);

        app(ProviderConfigurationService::class)->forgetCache();
        $this->assertNull(app(ProviderConfigurationService::class)->value('meta', 'client_secret'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.integration.provider.credential_cleared']);
    }

    public function test_clearing_a_field_the_provider_does_not_have_is_a_404(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson('/api/v1/admin/settings/integrations/providers/meta/credentials/developer_token')
            ->assertStatus(404);
    }

    // ── the five states ───────────────────────────────────────────────────────────────────────

    public function test_the_state_walks_from_not_configured_to_production_ready_and_never_skips_the_round_trip(): void
    {
        $settings = app(ProviderConfigurationService::class);

        $this->assertSame(ProviderSetupState::NotConfigured, $settings->state('google'));

        $settings->save('google', ['client_id' => 'id', 'client_secret' => 'sec']);
        $this->assertSame(ProviderSetupState::AwaitingCredentials, $settings->state('google'));
        $this->assertSame(['developer_token'], $settings->missing('google'));

        // Complete — but nothing has been proven yet, so it is ready to CONNECT, not ready for
        // production. A full form says somebody typed three strings and nothing more.
        $settings->save('google', ['developer_token' => 'dev']);
        $this->assertSame(ProviderSetupState::ReadyToConnect, $settings->state('google'));

        $settings->save('google', [], environment: 'production');
        $this->assertSame(ProviderSetupState::ReadyToConnect, $settings->state('google'));

        $settings->recordTest('google', true, 'ok');
        $this->assertSame(ProviderSetupState::CredentialsVerified, $settings->state('google'));

        // A known-broken configuration outranks a complete one. It is not "ready and also failing".
        $settings->recordTest('google', false, 'refused');
        $this->assertSame(ProviderSetupState::ConfigurationError, $settings->state('google'));
        $this->assertFalse($settings->isConnectable('google'));
    }

    /**
     * Changing a credential invalidates the verdict earned by the old one.
     *
     * Without this a provider stays `production_ready` on the strength of a round trip made with a
     * different client secret — the most dangerous stale fact this table can hold.
     */
    public function test_editing_a_credential_clears_the_previous_test_result(): void
    {
        $settings = app(ProviderConfigurationService::class);
        $settings->save('meta', ['client_id' => 'a', 'client_secret' => 'b'], environment: 'production');
        $settings->recordTest('meta', true, 'ok');
        $this->assertSame(ProviderSetupState::CredentialsVerified, $settings->state('meta'));

        $settings->save('meta', ['client_secret' => 'rotated']);

        $this->assertSame(ProviderSetupState::ReadyToConnect, $settings->state('meta'));
    }

    // ── enable / disable ──────────────────────────────────────────────────────────────────────

    /** Disabling stops new work and destroys nothing — including the credentials themselves. */
    public function test_disabling_a_provider_requires_a_reason_keeps_every_credential_and_blocks_connecting(): void
    {
        $settings = app(ProviderConfigurationService::class);
        $settings->save('meta', ['client_id' => 'a', 'client_secret' => 'b']);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/v1/admin/settings/integrations/providers/meta/status', ['enabled' => false])
            ->assertStatus(422);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/v1/admin/settings/integrations/providers/meta/status', [
                'enabled' => false, 'reason' => 'Rotating the app registration this week.',
            ])
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.connectable', false);

        $settings->forgetCache();
        $this->assertSame('b', $settings->value('meta', 'client_secret'));
        // Still `ready_to_connect` as a CONFIGURATION; `connectable` is what carries the refusal.
        $this->assertSame(ProviderSetupState::ReadyToConnect, $settings->state('meta'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.integration.provider.disabled']);
    }

    // ── the probe ─────────────────────────────────────────────────────────────────────────────

    /** No keys, no request. "Unreachable" and "unfinished" are different problems. */
    public function test_testing_an_unconfigured_provider_sends_nothing(): void
    {
        Http::fake();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/admin/settings/integrations/providers/snapchat/test')
            ->assertOk()
            ->assertJsonPath('data.passed', false);

        Http::assertNothingSent();
    }

    /** A provider that refuses the deliberately invalid CODE has recognised the app. */
    public function test_a_refusal_naming_the_grant_is_recorded_as_a_pass_with_a_narrow_claim(): void
    {
        app(ProviderConfigurationService::class)->save('meta', ['client_id' => 'a', 'client_secret' => 'b']);

        Http::fake(['*' => Http::response(['error' => ['message' => 'invalid_grant: code is invalid']], 400)]);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/admin/settings/integrations/providers/meta/test')
            ->assertOk()
            ->assertJsonPath('data.passed', true);

        // The message must not let anybody read a pass as "the integration works".
        $this->assertStringContainsString('client id and secret only', (string) $response->json('data.message'));
        $this->assertSame(ProviderSetupState::ReadyToConnect->value, $response->json('data.state'));
    }

    /** A provider that does not recognise the app is a configuration error, and says which. */
    public function test_a_rejected_client_is_recorded_as_a_configuration_error(): void
    {
        app(ProviderConfigurationService::class)->save('meta', ['client_id' => 'a', 'client_secret' => 'b']);

        Http::fake(['*' => Http::response(['error' => 'invalid_client'], 401)]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/admin/settings/integrations/providers/meta/test')
            ->assertOk()
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.state', ProviderSetupState::ConfigurationError->value);
    }

    /** An answer we cannot read is NOT a pass. The direction of doubt is fixed. */
    public function test_an_ambiguous_refusal_is_not_recorded_as_a_pass(): void
    {
        app(ProviderConfigurationService::class)->save('meta', ['client_id' => 'a', 'client_secret' => 'b']);

        Http::fake(['*' => Http::response('<html>Service Unavailable</html>', 400)]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/admin/settings/integrations/providers/meta/test')
            ->assertOk()
            ->assertJsonPath('data.passed', false);
    }

    /** A provider echoing our own secret back must not have it stored in a plaintext column. */
    public function test_the_recorded_message_never_contains_a_configured_value(): void
    {
        app(ProviderConfigurationService::class)->save('meta', [
            'client_id' => 'app-id-1234', 'client_secret' => 'echoed-secret-value',
        ]);

        Http::fake(['*' => Http::response(['error_description' => 'client_secret echoed-secret-value was rejected'], 400)]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/admin/settings/integrations/providers/meta/test')->assertOk();

        $stored = (string) ProviderConfiguration::query()->where('provider', 'meta')->value('last_test_message');
        $this->assertStringNotContainsString('echoed-secret-value', $stored);
        $this->assertStringContainsString('[redacted]', $stored);
    }

    // ── the connectors read the same configuration ────────────────────────────────────────────

    /**
     * The console is not a second configuration system.
     *
     * A key entered here has to reach the connector that calls the platform, or the admin page would
     * be a form that changes nothing.
     */
    public function test_a_credential_entered_in_the_console_is_what_the_connector_calls_with(): void
    {
        config()->set('ad_platforms.platforms.meta.client_id', 'from-the-environment');

        app(ProviderConfigurationService::class)->save('meta', [
            'client_id' => 'from-the-console', 'client_secret' => 'sec',
        ]);

        $creds = PlatformCredentials::for('meta');

        $this->assertSame('from-the-console', $creds->get('client_id'));
        $this->assertTrue($creds->isConfigured());
    }

    /** With nothing stored, the environment still works — which is what CI and local dev run on. */
    public function test_the_environment_remains_the_fallback_and_is_reported_as_the_source(): void
    {
        config()->set('ad_platforms.platforms.tiktok.client_id', 'env-id');
        config()->set('ad_platforms.platforms.tiktok.client_secret', 'env-secret');

        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/admin/settings/integrations/providers/tiktok')
            ->assertOk();

        $sources = collect($response->json('data.values'))->pluck('source', 'key');
        $this->assertSame('environment', $sources['client_id']);
        $this->assertSame(ProviderSetupState::ReadyToConnect->value, $response->json('data.state'));
    }

    /** Every provider in the catalogue resolves; a typo in one entry breaks the whole console. */
    public function test_every_catalogued_provider_has_a_resolvable_configuration(): void
    {
        $settings = app(ProviderConfigurationService::class);

        foreach (ProviderCatalogue::keys() as $key) {
            $summary = $settings->summary($key);

            $this->assertSame($key, $summary['key']);
            $this->assertContains($summary['state'], ProviderSetupState::values());
            $this->assertNotSame([], $summary['fields'], "{$key} declares no fields");
            $this->assertStringStartsWith('http', (string) $summary['redirect_uri']);
        }
    }
}
