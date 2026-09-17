<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Integrations\MetaCandidate\MetaCandidateConnection;
use App\Domains\Integrations\MetaCandidate\MetaCandidateCredentials;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\AuthorizationState;
use App\Domains\Integrations\OAuth\MetaCredentialProfile;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\Support\ProviderErrorText;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * META-CANDIDATE-001 — one Live Meta app, one isolated Candidate app, one callback URL.
 *
 * The claims pinned here, each of which is a way the migration could hurt a customer:
 *  - a Candidate flow can never send the Live App ID or secret, and a Live flow never the Candidate's;
 *  - the profile comes only from the signed state, and a missing or altered one fails closed;
 *  - the Candidate's token, connection and accounts never touch `provider_connections`,
 *    `integration_credentials` or `external_accounts`;
 *  - only the platform owner can run it, and a tenant cannot mint a Candidate state.
 */
final class MetaCandidateOAuthIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const LIVE_ID = 'live-app-111111';

    private const LIVE_SECRET = 'live-secret-AAAAAAAAAAAA';

    private const CAND_ID = 'cand-app-222222';

    private const CAND_SECRET = 'cand-secret-BBBBBBBBBBBB';

    private const CAND_CONFIG = 'cfg-333333';

    private User $owner;

    private User $platformOwner;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->owner = User::create(['name' => 'O', 'email' => 'o@agency.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);

        $this->platformOwner = User::create(['name' => 'P', 'email' => 'p@platform.test', 'password' => 'secret123']);
        $this->platformOwner->forceFill(['is_platform_admin' => true])->save();

        config()->set('ad_platforms.platforms.meta.client_id', self::LIVE_ID);
        config()->set('ad_platforms.platforms.meta.client_secret', self::LIVE_SECRET);
    }

    private function configureCandidate(): void
    {
        config()->set('ad_platforms.meta_candidate.client_id', self::CAND_ID);
        config()->set('ad_platforms.meta_candidate.client_secret', self::CAND_SECRET);
        config()->set('ad_platforms.meta_candidate.config_id', self::CAND_CONFIG);
        app(MetaCandidateCredentials::class)->forgetCache();
    }

    /** @return array<string,string> */
    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return $q;
    }

    private function startCandidate(): string
    {
        $url = $this->actingAs($this->platformOwner, 'sanctum')
            ->postJson('/api/v1/admin/settings/integrations/meta-candidate/test')
            ->assertOk()
            ->json('data.authorization_url');

        return $this->query((string) $url)['state'];
    }

    private function fakeMetaSuccess(): void
    {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'CAND-TOKEN-XYZ', 'expires_in' => 5184000]),
            'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => [
                ['id' => 'act_9', 'account_id' => '9', 'name' => 'Candidate account', 'account_status' => 1, 'currency' => 'SAR'],
            ]]),
            'graph.facebook.com/*/act_9/insights*' => Http::response(
                ['data' => [['spend' => '12.00', 'impressions' => '100']]],
                200,
                ['X-Business-Use-Case-Usage' => json_encode(['123' => [['type' => 'ads_insights', 'call_count' => 7, 'total_cputime' => 3, 'total_time' => 4]]])],
            ),
        ]);
    }

    // ── profiles ────────────────────────────────────────────────────────────────────────────────

    public function test_the_candidate_profile_never_borrows_a_live_value(): void
    {
        $candidate = PlatformCredentials::forMeta(MetaCredentialProfile::Candidate);

        $this->assertFalse($candidate->isConfigured());
        $this->assertSame(['client_id', 'client_secret', 'config_id'], $candidate->missing());
        $this->assertNull($candidate->get('client_id'));
        $this->assertSame(['ads_read'], $candidate->scopes());

        $this->configureCandidate();
        $candidate = PlatformCredentials::forMeta(MetaCredentialProfile::Candidate);
        $live = PlatformCredentials::forMeta(MetaCredentialProfile::Live);

        $this->assertSame(self::CAND_ID, $candidate->get('client_id'));
        $this->assertSame(self::LIVE_ID, $live->get('client_id'));
        $this->assertSame(MetaCredentialProfile::Candidate, $candidate->profile);
        $this->assertSame(MetaCredentialProfile::Live, $live->profile);
        $this->assertSame(['ads_read', 'ads_management', 'business_management'], $live->scopes(), 'Live scopes are unchanged.');
    }

    public function test_candidate_values_stored_through_the_console_win_over_the_environment_and_are_never_returned(): void
    {
        $this->configureCandidate();

        $response = $this->actingAs($this->platformOwner, 'sanctum')
            ->putJson('/api/v1/admin/settings/integrations/meta-candidate', [
                'client_secret' => 'stored-candidate-secret-CCCC',
                'scopes' => ['ads_read'],
            ])->assertOk();

        $this->assertStringNotContainsString('stored-candidate-secret', $response->getContent());
        $this->assertStringNotContainsString(self::CAND_SECRET, $response->getContent());
        $this->assertSame('stored-candidate-secret-CCCC', PlatformCredentials::forMeta(MetaCredentialProfile::Candidate)->get('client_secret'));
        $this->assertSame(self::LIVE_SECRET, PlatformCredentials::for('meta')->get('client_secret'), 'The Live row is untouched.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.integration.meta_candidate.updated']);
    }

    public function test_only_the_platform_owner_reaches_the_candidate_console(): void
    {
        $this->configureCandidate();

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/admin/settings/integrations/meta-candidate')->assertForbidden();
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/admin/settings/integrations/meta-candidate/test')->assertForbidden();
        $this->assertSame(0, MetaCandidateConnection::count());
    }

    public function test_an_unconfigured_candidate_issues_no_url(): void
    {
        $this->actingAs($this->platformOwner, 'sanctum')
            ->postJson('/api/v1/admin/settings/integrations/meta-candidate/test')
            ->assertStatus(422);

        $this->assertSame(0, MetaCandidateConnection::count());
    }

    // ── the dialog ──────────────────────────────────────────────────────────────────────────────

    public function test_the_candidate_dialog_uses_its_own_app_and_configuration_and_sends_no_scope(): void
    {
        $this->configureCandidate();

        $url = $this->actingAs($this->platformOwner, 'sanctum')
            ->postJson('/api/v1/admin/settings/integrations/meta-candidate/test')
            ->assertOk()->json('data.authorization_url');
        $q = $this->query($url);

        $this->assertStringStartsWith('https://www.facebook.com/v25.0/dialog/oauth?', $url);
        $this->assertSame(self::CAND_ID, $q['client_id']);
        $this->assertSame(self::CAND_CONFIG, $q['config_id']);
        $this->assertSame('code', $q['response_type']);
        $this->assertArrayNotHasKey('scope', $q);
        $this->assertStringEndsWith('/api/v1/oauth/ads/meta/callback', $q['redirect_uri'], 'The same callback URL as Live.');
        $this->assertStringNotContainsString(self::LIVE_ID, $url);
    }

    public function test_the_live_dialog_is_exactly_what_it_was(): void
    {
        $this->configureCandidate();

        $url = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/integrations/meta/oauth/start', ['profile' => 'candidate'])
            ->assertOk()->json('data.authorization_url');
        $q = $this->query($url);

        $this->assertSame(self::LIVE_ID, $q['client_id']);
        $this->assertSame('ads_read,ads_management,business_management', $q['scope']);
        $this->assertArrayNotHasKey('config_id', $q);

        // A tenant cannot talk its way into the Candidate profile.
        $record = AuthorizationState::claim($q['state'], 'meta');
        $this->assertSame('live', $record['profile']);
    }

    // ── state ───────────────────────────────────────────────────────────────────────────────────

    public function test_a_tampered_or_missing_profile_fails_closed(): void
    {
        foreach ([
            'flipped' => static fn (array $r) => ['profile' => 'candidate'] + $r,
            'missing' => static function (array $r) {
                unset($r['profile']);

                return $r;
            },
            'unknown' => static fn (array $r) => ['profile' => 'previous_live'] + $r,
            'unsigned' => static function (array $r) {
                unset($r['binding']);

                return $r;
            },
            'other tenant' => static fn (array $r) => ['tenant_id' => 'someone-else'] + $r,
        ] as $case => $mutate) {
            $state = AuthorizationState::issue($this->tenant->id, 'meta', $this->owner->id);
            $key = 'ads-oauth-state:'.$state;
            Cache::put($key, $mutate(Cache::get($key)), now()->addMinutes(5));

            $this->assertNull(AuthorizationState::claim($state, 'meta'), "{$case} must not resolve");
        }

        // A candidate state whose record lost its run is refused too.
        $state = AuthorizationState::issueMetaCandidate($this->platformOwner->id, 'run-1');
        $key = 'ads-oauth-state:'.$state;
        $record = Cache::get($key);
        unset($record['candidate_run_id']);
        Cache::put($key, $record, now()->addMinutes(5));
        $this->assertNull(AuthorizationState::claim($state, 'meta'));
    }

    public function test_a_tampered_profile_on_the_callback_exchanges_nothing(): void
    {
        $this->configureCandidate();
        Http::preventStrayRequests();
        Http::fake();

        $state = AuthorizationState::issue($this->tenant->id, 'meta', $this->owner->id);
        $key = 'ads-oauth-state:'.$state;
        Cache::put($key, ['profile' => 'candidate', 'candidate_run_id' => 'x', 'tenant_id' => null] + Cache::get($key), now()->addMinutes(5));

        $this->get("/api/v1/oauth/ads/meta/callback?code=abc&state={$state}")->assertRedirectContains('outcome=invalid_state');
        Http::assertNothingSent();
    }

    // ── the callback ────────────────────────────────────────────────────────────────────────────

    public function test_a_candidate_callback_uses_only_candidate_credentials_and_stores_nothing_live(): void
    {
        $this->configureCandidate();

        // A real Live connection already exists; it must come out byte-for-byte the same.
        $liveCredential = new IntegrationCredential;
        $liveCredential->forceFill([
            'tenant_id' => $this->tenant->id, 'provider' => 'meta', 'credential_scope' => 'tenant_shared',
            'credential_type' => 'oauth2', 'encrypted_payload' => json_encode(['access_token' => 'LIVE-TOKEN']), 'status' => 'active',
        ])->save();
        $live = new ProviderConnection;
        $live->forceFill([
            'tenant_id' => $this->tenant->id, 'credential_id' => $liveCredential->id, 'provider' => 'meta',
            'connection_name' => 'Meta', 'scope' => 'tenant_shared', 'status' => 'connected',
        ])->save();
        $before = [ProviderConnection::withoutGlobalScopes()->get()->toArray(), IntegrationCredential::withoutGlobalScopes()->get()->map->revealPayload()->all()];

        $this->fakeMetaSuccess();
        $state = $this->startCandidate();

        $this->get("/api/v1/oauth/ads/meta/callback?code=abc&state={$state}")
            ->assertRedirectContains('/admin/settings/integrations/meta-candidate?outcome=succeeded');

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'oauth/access_token')
            && ($r->data()['client_id'] ?? null) === self::CAND_ID
            && ($r->data()['client_secret'] ?? null) === self::CAND_SECRET);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url().json_encode($r->data()), self::LIVE_ID)
            || str_contains($r->url().json_encode($r->data()), self::LIVE_SECRET));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'me/adaccounts')
            && $r->header('Authorization') === ['Bearer CAND-TOKEN-XYZ']);

        $run = MetaCandidateConnection::sole();
        $this->assertSame('candidate', $run->profile);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame(['ok'], array_values(array_unique(array_column($run->steps, 'status'))));
        $this->assertSame('act_9', $run->discovered_accounts[0]['id']);

        // Nothing reached the Live tables.
        $this->assertSame($before, [ProviderConnection::withoutGlobalScopes()->get()->toArray(), IntegrationCredential::withoutGlobalScopes()->get()->map->revealPayload()->all()]);
        $this->assertSame(0, ExternalAccount::withoutGlobalScopes()->count(), 'Candidate accounts are not bindable inventory.');

        // The token is encrypted at rest and absent from anything shown.
        $this->assertStringNotContainsString('CAND-TOKEN-XYZ', (string) \DB::table('meta_candidate_connections')->value('encrypted_token'));
        $this->assertStringNotContainsString('CAND-TOKEN-XYZ', json_encode($run->toReport()));
    }

    public function test_a_live_callback_never_uses_candidate_credentials(): void
    {
        $this->configureCandidate();
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'LIVE-AT', 'expires_in' => 5184000]),
            'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => [['id' => 'act_1', 'name' => 'Main', 'currency' => 'SAR', 'account_status' => 1]]]),
        ]);

        $url = $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/integrations/meta/oauth/start')->json('data.authorization_url');
        $state = $this->query($url)['state'];

        $this->get("/api/v1/oauth/ads/meta/callback?code=abc&state={$state}")->assertRedirectContains('outcome=connected');

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'oauth/access_token')
            && ($r->data()['client_id'] ?? null) === self::LIVE_ID && ($r->data()['client_secret'] ?? null) === self::LIVE_SECRET);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url().json_encode($r->data()), self::CAND_ID)
            || str_contains($r->url().json_encode($r->data()), self::CAND_SECRET));
        $this->assertSame(0, MetaCandidateConnection::count());
    }

    public function test_a_token_without_expiry_is_not_sent_for_a_long_lived_exchange(): void
    {
        $this->configureCandidate();
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'CAND-SYSTEM-USER-TOKEN']),
            'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => [['id' => 'act_9', 'name' => 'A', 'account_status' => 1, 'currency' => 'SAR']]]),
            'graph.facebook.com/*/act_9/insights*' => Http::response(['data' => []]),
        ]);
        $state = $this->startCandidate();

        $this->get("/api/v1/oauth/ads/meta/callback?code=abc&state={$state}")->assertRedirectContains('outcome=succeeded');

        Http::assertSentCount(3);
        Http::assertNotSent(fn (HttpRequest $r) => ($r->data()['grant_type'] ?? null) === 'fb_exchange_token');
    }

    public function test_a_candidate_state_cannot_be_replayed(): void
    {
        $this->configureCandidate();
        $this->fakeMetaSuccess();
        $state = $this->startCandidate();

        $this->get("/api/v1/oauth/ads/meta/callback?code=abc&state={$state}")->assertRedirectContains('outcome=succeeded');
        $this->get("/api/v1/oauth/ads/meta/callback?code=abc&state={$state}")->assertRedirectContains('outcome=invalid_state');
    }

    public function test_a_refused_exchange_records_metas_error_and_never_a_secret(): void
    {
        $this->configureCandidate();
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['error' => [
                'message' => 'Error validating client secret '.self::CAND_SECRET,
                'type' => 'OAuthException', 'code' => 1, 'error_subcode' => 33, 'fbtrace_id' => 'TRACE123',
            ]], 400),
        ]);
        $state = $this->startCandidate();

        $this->get("/api/v1/oauth/ads/meta/callback?code=abc&state={$state}")->assertRedirectContains('outcome=failed');

        $run = MetaCandidateConnection::sole();
        $step = collect($run->steps)->firstWhere('key', 'token_exchange');
        $this->assertSame('failed', $step['status']);
        $this->assertSame(1, $step['error']['code']);
        $this->assertSame(33, $step['error']['subcode']);
        $this->assertSame('TRACE123', $step['error']['fbtrace_id']);
        $this->assertStringNotContainsString(self::CAND_SECRET, json_encode($run->steps));
        $this->assertSame('pending', collect($run->steps)->firstWhere('key', 'account_discovery')['status']);
    }

    public function test_a_consent_refusal_is_recorded_as_the_consent_step(): void
    {
        $this->configureCandidate();
        Http::preventStrayRequests();
        Http::fake();
        $state = $this->startCandidate();

        $this->get("/api/v1/oauth/ads/meta/callback?error=access_denied&error_reason=user_denied&error_description=Permissions+error&state={$state}")
            ->assertRedirectContains('outcome=failed');

        $step = collect(MetaCandidateConnection::sole()->steps)->firstWhere('key', 'consent');
        $this->assertSame('failed', $step['status']);
        $this->assertSame('access_denied', $step['error']['type']);
        Http::assertNothingSent();
    }

    public function test_credentials_changed_mid_flight_are_not_sent(): void
    {
        $this->configureCandidate();
        Http::preventStrayRequests();
        Http::fake();
        $state = $this->startCandidate();

        config()->set('ad_platforms.meta_candidate.client_secret', 'a-different-secret-DDDD');
        app(MetaCandidateCredentials::class)->forgetCache();

        $this->get("/api/v1/oauth/ads/meta/callback?code=abc&state={$state}")->assertRedirectContains('outcome=failed');
        Http::assertNothingSent();
    }

    public function test_the_candidate_secret_is_redacted_by_value_wherever_it_is_quoted(): void
    {
        $this->configureCandidate();

        $text = 'cURL error 28 for https://graph.facebook.com/x?sig='.self::CAND_SECRET.' and pass '.self::LIVE_SECRET;

        foreach ([ProviderErrorText::forDisplay($text), ProviderErrorText::forStorage($text)] as $out) {
            $this->assertStringNotContainsString(self::CAND_SECRET, (string) $out);
            $this->assertStringNotContainsString(self::LIVE_SECRET, (string) $out);
        }
    }
}
