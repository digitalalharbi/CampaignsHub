<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\MetaCandidate\MetaSaasCredentials;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\MetaCredentialProfile;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Legal\Http\Controllers\DataDeletionController;
use App\Domains\Legal\Models\DataRequest;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * META-MULTI-APP-001 — the Data Deletion callback of each Meta app is bound to ITS endpoint: a signed_request valid for one app is
 * refused at the other's, stores nothing, and a deletion never reaches a connection of the other app. Secrets are fixtures.
 */
final class MetaDeletionCallbackBindingTest extends TestCase
{
    use RefreshDatabase;

    private const LIVE_URL = '/api/v1/webhooks/data-deletion/meta';

    private const SAAS_URL = '/api/v1/webhooks/data-deletion/meta-saas';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ad_platforms.platforms.meta.client_id', 'live-id');
        config()->set('ad_platforms.platforms.meta.client_secret', 'live-secret');
        app(MetaSaasCredentials::class)->save(['client_id' => '2604968763258530', 'client_secret' => 'saas-secret', 'config_id' => '1138211818567426']);
        app(MetaSaasCredentials::class)->forgetCache();
    }

    private function sign(array $payload, string $secret): string
    {
        $encoded = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $encoded, $secret, true)), '+/', '-_'), '=').'.'.$encoded;
    }

    public function test_a_request_valid_for_one_app_is_refused_at_the_other_apps_endpoint_and_stores_nothing(): void
    {
        $this->postJson(self::LIVE_URL, ['signed_request' => $this->sign(['user_id' => '777'], 'saas-secret')])->assertStatus(401);
        $this->postJson(self::SAAS_URL, ['signed_request' => $this->sign(['user_id' => '777'], 'live-secret')])->assertStatus(401);

        $this->assertSame(0, DataRequest::count());
    }

    public function test_with_an_identical_secret_on_both_apps_each_endpoint_still_files_under_its_own_app(): void
    {
        app(MetaSaasCredentials::class)->save(['client_secret' => 'live-secret']);
        app(MetaSaasCredentials::class)->forgetCache();
        $signed = $this->sign(['user_id' => '777'], 'live-secret');

        $this->postJson(self::LIVE_URL, ['signed_request' => $signed])->assertOk();
        $this->postJson(self::SAAS_URL, ['signed_request' => $signed])->assertOk();

        $this->assertSame(['meta', 'meta-saas'], DataRequest::query()->pluck('source_provider')->sort()->values()->all());
    }

    public function test_unsigned_tampered_and_unknown_secret_requests_are_refused(): void
    {
        $good = $this->sign(['user_id' => '777'], 'saas-secret');
        [$sig, $payload] = explode('.', $good, 2);

        $this->postJson(self::SAAS_URL, [])->assertStatus(401);
        $this->postJson(self::SAAS_URL, ['signed_request' => $this->sign(['user_id' => '777'], 'unknown')])->assertStatus(401);
        $this->postJson(self::SAAS_URL, ['signed_request' => $sig.'.'.rtrim(strtr(base64_encode('{"user_id":"999"}'), '+/', '-_'), '=')])->assertStatus(401);
        $this->assertSame(0, DataRequest::count());
    }

    public function test_a_replayed_request_is_idempotent_with_the_same_confirmation_code_and_one_row(): void
    {
        $signed = $this->sign(['user_id' => '777', 'issued_at' => time()], 'saas-secret');

        $first = $this->postJson(self::SAAS_URL, ['signed_request' => $signed])->assertOk()->json('confirmation_code');
        $second = $this->postJson(self::SAAS_URL, ['signed_request' => $signed])->assertOk()->json('confirmation_code');

        $this->assertSame($first, $second);
        $this->assertSame(1, DataRequest::count());
    }

    /** `issued_at` is not guaranteed by Meta's format, so its absence means «no window», never rejection; idempotency still protects. */
    public function test_a_request_without_issued_at_is_accepted_once_and_a_replay_is_idempotent(): void
    {
        $signed = $this->sign(['user_id' => '555'], 'live-secret');

        $first = $this->postJson(self::LIVE_URL, ['signed_request' => $signed])->assertOk()->json('confirmation_code');
        $this->travel(2)->days();
        $second = $this->postJson(self::LIVE_URL, ['signed_request' => $signed])->assertOk()->json('confirmation_code');

        $this->assertSame($first, $second);
        $this->assertSame(1, DataRequest::count());
    }

    public function test_a_request_older_than_the_window_or_from_the_future_is_refused(): void
    {
        $this->assertSame(86400, DataDeletionController::SIGNED_REQUEST_MAX_AGE_SECONDS);

        $this->postJson(self::SAAS_URL, ['signed_request' => $this->sign(['user_id' => '1', 'issued_at' => time() - 86400 - 60], 'saas-secret')])->assertStatus(401);
        $this->postJson(self::SAAS_URL, ['signed_request' => $this->sign(['user_id' => '2', 'issued_at' => time() + 3600], 'saas-secret')])->assertStatus(401);
        $this->postJson(self::SAAS_URL, ['signed_request' => $this->sign(['user_id' => '3', 'issued_at' => time() - 3600], 'saas-secret')])->assertOk();
        $this->assertSame(1, DataRequest::count());
    }

    public function test_a_payload_naming_another_app_or_algorithm_is_refused_even_when_correctly_signed(): void
    {
        $this->postJson(self::SAAS_URL, ['signed_request' => $this->sign(['user_id' => '1', 'app_id' => 'live-id'], 'saas-secret')])->assertStatus(401);
        $this->postJson(self::SAAS_URL, ['signed_request' => $this->sign(['user_id' => '2', 'algorithm' => 'none'], 'saas-secret')])->assertStatus(401);
        $this->postJson(self::SAAS_URL, ['signed_request' => $this->sign(['user_id' => '3', 'app_id' => '2604968763258530', 'algorithm' => 'HMAC-SHA256'], 'saas-secret')])->assertOk();
        $this->assertSame(1, DataRequest::count());
    }

    public function test_a_deletion_on_one_apps_endpoint_never_touches_the_other_apps_connections(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'del-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $tokens = new OAuthTokens('T', null, Carbon::now()->addDays(5));
        $live = app(TokenVault::class)->open(tenantId: (string) $tenant->id, provider: 'meta', tokens: $tokens, connectionName: 'live', externalOwnerId: '777');
        $saas = app(TokenVault::class)->open(tenantId: (string) $tenant->id, provider: 'meta', tokens: $tokens, connectionName: 'saas', externalOwnerId: '777', appProfile: MetaCredentialProfile::Saas);
        $snapshot = fn (): string => DB::table('provider_connections')->orderBy('id')->get()->toJson().DB::table('integration_credentials')->orderBy('id')->get()->toJson();
        $before = $snapshot();

        $this->postJson(self::SAAS_URL, ['signed_request' => $this->sign(['user_id' => '777'], 'saas-secret')])->assertOk();
        $this->assertSame($before, $snapshot(), 'no connection or credential of either app changed');
        $this->assertSame(['meta-saas'], DataRequest::query()->pluck('source_provider')->all());

        $this->postJson(self::LIVE_URL, ['signed_request' => $this->sign(['user_id' => '777'], 'live-secret')])->assertOk();
        $this->assertSame($before, $snapshot());
        $this->assertSame(2, DataRequest::count(), 'the same external id is a different person per app: two requests');
        $this->assertSame(2, ProviderConnection::withoutGlobalScopes()->whereNotIn('status', ['revoked'])->count());
        $this->assertNotNull($live);
        $this->assertNotNull($saas);
    }
}
