<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\MetaCandidate\MetaCandidateCredentials;
use App\Domains\Integrations\MetaCandidate\MetaSaasCredentials;
use App\Domains\Integrations\Webhooks\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * META-MULTI-APP-001 — an inbound Meta webhook is verified against EACH registered app's secret; exactly one must validate, and the
 * app that did is reported. Unsigned, tampered, unknown-secret and no-app-configured deliveries are refused. Secrets are fixtures.
 */
final class MetaWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = '{"entry":[{"id":"act_1"}],"event_id":"e-1"}';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ad_platforms.platforms.meta.client_id', 'live-id');
        config()->set('ad_platforms.platforms.meta.client_secret', 'live-secret-AAAA');
    }

    private function configureSaas(string $secret = 'saas-secret-BBBB'): void
    {
        app(MetaSaasCredentials::class)->save(['client_id' => '2604968763258530', 'client_secret' => $secret, 'config_id' => '1138211818567426']);
        app(MetaSaasCredentials::class)->forgetCache();
    }

    private function sig(string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    /** @return array{verified: bool, reason: ?string, app?: string} */
    private function check(string $body, ?string $header): array
    {
        return app(WebhookSignature::class)->check('meta', $body, $header);
    }

    public function test_a_delivery_signed_by_the_saas_secret_validates_and_reports_the_saas_app(): void
    {
        $this->configureSaas();

        $this->assertSame('saas', $this->check(self::BODY, $this->sig(self::BODY, 'saas-secret-BBBB'))['app']);
        $this->post_signed(self::BODY, 'saas-secret-BBBB')->assertOk();
    }

    public function test_a_delivery_signed_by_the_live_secret_validates_as_live(): void
    {
        $this->configureSaas();

        $result = $this->check(self::BODY, $this->sig(self::BODY, 'live-secret-AAAA'));

        $this->assertTrue($result['verified']);
        $this->assertSame('live', $result['app']);
    }

    public function test_unknown_secret_unsigned_and_tampered_deliveries_are_refused_and_record_nothing(): void
    {
        $this->configureSaas();

        $this->assertFalse($this->check(self::BODY, $this->sig(self::BODY, 'unknown-secret'))['verified']);
        $this->assertFalse($this->check(self::BODY, null)['verified']);
        $this->assertFalse($this->check(self::BODY, '')['verified']);
        $this->assertFalse($this->check(self::BODY.' ', $this->sig(self::BODY, 'saas-secret-BBBB'))['verified'], 'a tampered body');
        $this->post_signed(self::BODY, 'unknown-secret')->assertStatus(401);
        $this->postJson('/api/v1/webhooks/ads/meta', ['entry' => []])->assertStatus(401);
        $this->assertDatabaseCount('integration_webhook_events', 0);
    }

    public function test_with_the_saas_app_unconfigured_only_live_validates(): void
    {
        $this->assertTrue($this->check(self::BODY, $this->sig(self::BODY, 'live-secret-AAAA'))['verified']);
        $this->assertFalse($this->check(self::BODY, $this->sig(self::BODY, 'saas-secret-BBBB'))['verified']);
    }

    public function test_with_no_app_configured_everything_is_refused(): void
    {
        config()->set('ad_platforms.platforms.meta.client_secret', null);

        $this->assertFalse($this->check(self::BODY, $this->sig(self::BODY, 'live-secret-AAAA'))['verified']);
        $this->assertFalse($this->check(self::BODY, null)['verified']);
    }

    public function test_two_apps_sharing_one_secret_cannot_say_whose_delivery_it_is_and_is_refused(): void
    {
        $this->configureSaas('live-secret-AAAA');

        $this->assertFalse($this->check(self::BODY, $this->sig(self::BODY, 'live-secret-AAAA'))['verified']);
    }

    public function test_the_candidate_app_validates_as_candidate_when_configured(): void
    {
        app(MetaCandidateCredentials::class)->save(['client_id' => 'cand', 'client_secret' => 'cand-secret-CCCC', 'config_id' => 'c']);
        app(MetaCandidateCredentials::class)->forgetCache();

        $this->assertSame('candidate', $this->check(self::BODY, $this->sig(self::BODY, 'cand-secret-CCCC'))['app']);
    }

    private function post_signed(string $body, string $secret): TestResponse
    {
        return $this->call('POST', '/api/v1/webhooks/ads/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $this->sig($body, $secret),
        ], $body);
    }
}
