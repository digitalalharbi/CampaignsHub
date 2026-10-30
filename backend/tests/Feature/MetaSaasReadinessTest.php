<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\MetaCandidate\MetaCandidateConnection;
use App\Domains\Integrations\MetaCandidate\MetaSaasCredentials;
use App\Domains\Integrations\MetaCandidate\MetaSaasReadiness;
use App\Domains\Integrations\Models\ProviderConfiguration;
use App\Domains\Integrations\OAuth\MetaCredentialProfile;
use App\Domains\Legal\Models\DataRequest;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * META-SAAS-APP-001 — the SaaS app's readiness list says what is proven, what is untested, what waits for credentials and what only
 * the Owner can do. Every status comes from evidence the platform holds; none is a hope.
 */
final class MetaSaasReadinessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->admin = User::create(['name' => 'P', 'email' => 'p@readiness.test', 'password' => 'secret123']);
        $this->admin->forceFill(['is_platform_admin' => true])->save();
    }

    /** @return array<string, string> item key => status, as the admin page receives it */
    private function statuses(): array
    {
        $items = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/settings/integrations/meta-saas')->assertOk()->json('data.readiness');

        return array_column($items, 'status', 'key');
    }

    private function configure(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/settings/integrations/meta-saas', [
            'client_id' => 'saas-id-1', 'client_secret' => 'saas-secret-1', 'config_id' => 'cfg-1',
        ])->assertOk();
        app(MetaSaasCredentials::class)->forgetCache();
    }

    private function passTest(array $discovered): void
    {
        $run = new MetaCandidateConnection;
        $run->forceFill([
            'profile' => 'saas', 'started_by' => $this->admin->id, 'status' => 'succeeded', 'discovered_accounts' => $discovered,
            'credential_fingerprint' => app(MetaSaasCredentials::class)->fingerprint(), 'started_at' => now(),
        ])->save();
    }

    public function test_with_nothing_configured_only_the_platform_side_is_ready_and_nothing_is_claimed(): void
    {
        $s = $this->statuses();

        $this->assertSame(['ready'], array_values(array_unique(array_intersect_key($s, array_flip(['legal_pages', 'redirect_uri'])))));
        foreach (['deletion_callback', 'credentials', 'oauth_round_trip', 'account_discovery', 'sync_and_reports'] as $key) {
            $this->assertSame('needs_credentials', $s[$key], $key);
        }
        foreach (['test_workspace', 'meta_dashboard', 'business_verification', 'app_review', 'new_connections'] as $key) {
            $this->assertSame('owner_action', $s[$key], $key);
        }
        $this->assertEmpty(array_diff($s, MetaSaasReadiness::STATUSES));
    }

    public function test_entered_credentials_are_not_a_tested_connection(): void
    {
        $this->configure();
        $s = $this->statuses();

        $this->assertSame('ready', $s['credentials']);
        $this->assertSame('not_verified', $s['oauth_round_trip']);
        $this->assertSame('not_verified', $s['account_discovery']);
        $this->assertSame('not_verified', $s['deletion_callback'], 'a secret to verify with is not a call Meta made');
        $this->assertSame('owner_action', $s['meta_dashboard']);
    }

    public function test_a_passed_round_trip_proves_the_login_settings_and_discovery_until_the_credentials_change(): void
    {
        $this->configure();
        $this->passTest([['id' => 'act_1', 'name' => 'Test account']]);
        $s = $this->statuses();

        $this->assertSame('ready', $s['oauth_round_trip']);
        $this->assertSame('ready', $s['meta_dashboard']);
        $this->assertSame('ready', $s['account_discovery']);
        $this->assertSame('not_verified', $s['sync_and_reports'], 'a test round trip syncs nothing');

        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/settings/integrations/meta-saas', ['client_secret' => 'rotated'])->assertOk();
        app(MetaSaasCredentials::class)->forgetCache();
        $s = $this->statuses();
        $this->assertSame('not_verified', $s['oauth_round_trip']);
        $this->assertSame('owner_action', $s['meta_dashboard']);
    }

    public function test_a_round_trip_that_found_no_account_does_not_prove_discovery(): void
    {
        $this->configure();
        $this->passTest([]);

        $this->assertSame('not_verified', $this->statuses()['account_discovery']);
    }

    public function test_the_deletion_callback_url_is_on_the_public_api_origin_not_the_requests_scheme_and_host(): void
    {
        // Production showed `http://campaignshub.io/...`: `route()` followed the proxied request, and Meta refuses a non-https URL.
        config(['ad_platforms.redirect_base' => 'https://api.example.test']);

        $items = $this->actingAs($this->admin, 'sanctum')->getJson('http://campaignshub.test/api/v1/admin/settings/integrations/meta-saas')
            ->assertOk()->json('data.readiness');
        $detail = array_column($items, 'detail', 'key')['deletion_callback'];

        $this->assertSame('https://api.example.test/api/v1/webhooks/data-deletion/meta-saas', $detail['callback_url']);
    }

    public function test_the_deletion_callback_is_ready_only_once_meta_really_called_it(): void
    {
        $this->configure();
        $request = new DataRequest;
        $request->fill([
            'type' => 'delete_data', 'name' => 'META-SAAS user', 'email' => 'meta-saas+1@deletion.invalid',
            'details' => 'x', 'reference' => DataRequest::makeReference(), 'locale' => 'en',
        ]);
        $request->status = 'pending';
        $request->source = 'provider_callback';
        $request->source_provider = 'meta';
        $request->save();
        $this->assertSame('not_verified', $this->statuses()['deletion_callback'], 'a call to the Live callback says nothing about this one');

        $request->source_provider = 'meta-saas';
        $request->save();
        $this->assertSame('ready', $this->statuses()['deletion_callback']);
    }

    public function test_meta_approval_is_the_owners_recorded_statement_and_withdrawing_it_returns_new_connections_to_live(): void
    {
        $this->configure();
        $this->passTest([['id' => 'act_1']]);
        $admin = $this->actingAs($this->admin, 'sanctum');

        $admin->putJson('/api/v1/admin/settings/integrations/meta-saas/review', ['business_verified' => true])->assertStatus(422);
        $admin->putJson('/api/v1/admin/settings/integrations/meta-saas/review', ['business_verified' => true, 'advanced_access_granted' => true])->assertOk();
        $items = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/settings/integrations/meta-saas')->json('data.readiness');
        $this->assertSame($this->admin->id, collect($items)->firstWhere('key', 'business_verification')['detail']['confirmed_by']);
        $s = $this->statuses();
        $this->assertSame('ready', $s['business_verification']);
        $this->assertSame('ready', $s['app_review']);
        $this->assertSame('owner_action', $s['new_connections'], 'approval alone moves no connection');
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.integration.meta_saas.review_confirmed']);

        // Meta's approval alone does not make the SaaS app primary: a real journey in the test workspace is still missing
        // (MetaSaasModesTest covers turning it on).
        $admin->putJson('/api/v1/admin/settings/integrations/meta-saas/routing', ['default' => 'saas', 'confirm' => true, 'approve_primary' => true, 'acknowledge_existing_unchanged' => true])
            ->assertStatus(409)->assertJsonPath('errors.routing', fn (array $unmet) => in_array('test_journey_complete', $unmet, true));
        ProviderConfiguration::query()->where('provider', MetaCredentialProfile::Saas->storageKey())->update(['enabled' => true]);
        $this->assertSame('ready', $this->statuses()['new_connections']);

        $admin->deleteJson('/api/v1/admin/settings/integrations/meta-saas/review')->assertOk()
            ->assertJsonPath('data.routing.default_for_new_connections', 'live');
        $this->assertNull(ProviderConfiguration::query()->where('provider', MetaCredentialProfile::Saas->storageKey())->value('review_confirmed_at'));
        $this->assertSame('owner_action', $this->statuses()['app_review']);
    }

    public function test_only_a_platform_admin_records_meta_approval(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u@readiness.test', 'password' => 'secret123']);

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/admin/settings/integrations/meta-saas/review', ['business_verified' => true, 'advanced_access_granted' => true])
            ->assertForbidden();
        $this->assertNull(ProviderConfiguration::query()->where('provider', MetaCredentialProfile::Saas->storageKey())->value('review_confirmed_at'));
    }
}
