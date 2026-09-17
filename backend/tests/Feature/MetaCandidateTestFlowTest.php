<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\MetaCandidate\MetaCandidateConnection;
use App\Domains\Integrations\MetaCandidate\MetaCandidateCredentials;
use App\Domains\Integrations\MetaCandidate\MetaUsageHeader;
use App\Domains\Integrations\Models\ProviderConfiguration;
use App\Domains\Integrations\Review\ReviewChecklistService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * META-CANDIDATE-001 part 2 — the checklist, its read-only diagnostics, the honest scope display, and
 * the two Meta headers that must stay handled.
 */
final class MetaCandidateTestFlowTest extends TestCase
{
    use RefreshDatabase;

    private const CAND_SECRET = 'cand-secret-BBBBBBBBBBBB';

    private User $platformOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformOwner = User::create(['name' => 'P', 'email' => 'p@platform.test', 'password' => 'secret123']);
        $this->platformOwner->forceFill(['is_platform_admin' => true])->save();

        config()->set('ad_platforms.platforms.meta.client_id', 'live-app-111111');
        config()->set('ad_platforms.platforms.meta.client_secret', 'live-secret-AAAAAAAAAAAA');
        config()->set('ad_platforms.meta_candidate.client_id', 'cand-app-222222');
        config()->set('ad_platforms.meta_candidate.client_secret', self::CAND_SECRET);
        config()->set('ad_platforms.meta_candidate.config_id', 'cfg-333333');
    }

    private function runRoundTrip(): MetaCandidateConnection
    {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'CAND-TOKEN-XYZ', 'expires_in' => 5184000]),
            'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => [
                ['id' => 'act_9', 'name' => 'Candidate account', 'account_status' => 1, 'currency' => 'SAR'],
            ]]),
            'graph.facebook.com/*/act_9/insights*' => Http::response(
                ['data' => [['spend' => '12.00']]],
                200,
                ['X-Business-Use-Case-Usage' => json_encode(['123' => [['type' => 'ads_insights', 'call_count' => 7, 'total_cputime' => 3, 'total_time' => 4]]])],
            ),
        ]);

        $url = $this->actingAs($this->platformOwner, 'sanctum')
            ->postJson('/api/v1/admin/settings/integrations/meta-candidate/test')->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->get("/api/v1/oauth/ads/meta/callback?code=abc&state={$q['state']}");

        return MetaCandidateConnection::latestRun();
    }

    public function test_the_console_shows_every_step_of_the_latest_run_and_no_token(): void
    {
        $this->runRoundTrip();

        $response = $this->actingAs($this->platformOwner, 'sanctum')
            ->getJson('/api/v1/admin/settings/integrations/meta-candidate')->assertOk();

        $this->assertSame(
            ['oauth_start', 'consent', 'token_exchange', 'account_discovery', 'ads_read'],
            array_column($response->json('data.latest_run.steps'), 'key'),
        );
        $this->assertSame('succeeded', $response->json('data.latest_run.status'));
        $this->assertSame(['ads_read'], $response->json('data.credentials.effective_scopes'));
        $this->assertStringNotContainsString('CAND-TOKEN-XYZ', $response->getContent());
        $this->assertStringNotContainsString(self::CAND_SECRET, $response->getContent());
        $this->assertStringNotContainsString('cand-app-222222', $response->getContent());
    }

    public function test_the_ads_read_step_records_the_business_use_case_usage_header(): void
    {
        $run = $this->runRoundTrip();
        $step = collect($run->steps)->firstWhere('key', 'ads_read');

        $this->assertSame('ok', $step['status']);
        $this->assertSame(1, $step['detail']['rows']);
        $this->assertTrue($step['detail']['business_use_case_usage']['present']);
        $this->assertSame(7, $step['detail']['business_use_case_usage']['max_call_count']);
    }

    public function test_the_command_prints_the_last_result_and_writes_nothing_and_leaks_nothing(): void
    {
        $this->runRoundTrip();
        Http::preventStrayRequests();
        Http::fake();

        $before = $this->digest();
        $exit = Artisan::call('integrations:meta-candidate');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('status          : succeeded', $output);
        $this->assertStringContainsString('[ok     ] ads_read', $output);
        foreach (['CAND-TOKEN-XYZ', self::CAND_SECRET, 'cand-app-222222', 'cfg-333333'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
        $this->assertSame($before, $this->digest(), 'The command writes nothing.');
        Http::assertNothingSent();
    }

    public function test_the_command_prints_metas_error_identifiers_for_a_failed_step(): void
    {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'T', 'expires_in' => 100]),
            'graph.facebook.com/*/me/adaccounts*' => Http::response(['error' => [
                'message' => '(#200) Missing permissions', 'type' => 'OAuthException', 'code' => 200, 'fbtrace_id' => 'FBT-9',
            ]], 403),
        ]);
        $url = $this->actingAs($this->platformOwner, 'sanctum')
            ->postJson('/api/v1/admin/settings/integrations/meta-candidate/test')->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->get("/api/v1/oauth/ads/meta/callback?code=abc&state={$q['state']}")->assertRedirectContains('outcome=failed');

        Artisan::call('integrations:meta-candidate');
        $output = Artisan::output();

        $this->assertStringContainsString('[failed ] account_discovery — HTTP 403 · code 200 · OAuthException · fbtrace_id FBT-9', $output);
        $this->assertStringContainsString('[pending] ads_read', $output);
    }

    public function test_the_command_says_so_when_nothing_has_run(): void
    {
        Artisan::call('integrations:meta-candidate');

        $this->assertStringContainsString('none has run', Artisan::output());
    }

    // ── scopes shown are the scopes configured ────────────────────────────────────────────────────

    public function test_the_review_checklist_shows_the_effective_scopes_not_the_catalogue_default(): void
    {
        $scopes = static fn (): string => collect(app(ReviewChecklistService::class)->for('meta')['items'])
            ->firstWhere('key', 'least_privilege')['value'];

        $this->assertSame('ads_read · ads_management · business_management', $scopes());

        ProviderConfiguration::query()->create(['provider' => 'meta', 'environment' => 'production', 'enabled' => true, 'scopes' => ['ads_read']]);
        app(ProviderConfigurationService::class)->forgetCache('meta');

        $this->assertSame('ads_read', $scopes());
    }

    public function test_the_candidate_default_scope_is_ads_read_only_and_an_override_is_shown(): void
    {
        $this->assertSame(['ads_read'], app(MetaCandidateCredentials::class)->scopes());

        $this->actingAs($this->platformOwner, 'sanctum')
            ->putJson('/api/v1/admin/settings/integrations/meta-candidate', ['scopes' => ['ads_read', 'business_management']])
            ->assertOk()
            ->assertJsonPath('data.credentials.effective_scopes', ['ads_read', 'business_management']);
    }

    public function test_the_meta_probe_names_the_configured_scopes_not_a_fixed_trio(): void
    {
        app(ProviderConfigurationService::class)->save('meta', ['client_id' => 'app-id-1234', 'client_secret' => 'app-secret-5678']);
        ProviderConfiguration::query()->where('provider', 'meta')->update(['scopes' => json_encode(['ads_read'])]);
        app(ProviderConfigurationService::class)->forgetCache('meta');
        Http::fake(['*' => Http::response(['access_token' => 'app-id-1234|APP-TOKEN'], 200)]);

        $message = (string) $this->actingAs($this->platformOwner, 'sanctum')
            ->postJson('/api/v1/admin/settings/integrations/providers/meta/test')
            ->assertOk()->json('data.message');

        $this->assertStringContainsString('(ads_read)', $message);
        $this->assertStringNotContainsString('ads_management', $message);
        $this->assertStringNotContainsString('business_management', $message);
    }

    // ── the Meta headers stay handled (they are Meta's, not X Ads') ───────────────────────────────

    public function test_meta_headers_remain_declared_and_handled(): void
    {
        $meta = ProviderCatalogue::get('meta');

        $this->assertSame('X-Hub-Signature-256', $meta->webhookSignatureHeader);
        $this->assertStringContainsString('X-Business-Use-Case-Usage', $meta->rateLimitNote);
        // They belong to Meta: X Ads declares neither.
        $x = ProviderCatalogue::get('x');
        $this->assertNotSame('X-Hub-Signature-256', $x->webhookSignatureHeader);
        $this->assertStringNotContainsString('X-Business-Use-Case-Usage', $x->rateLimitNote);

        $this->assertSame(
            ['present' => true, 'max_call_count' => 90, 'max_total_cputime' => 5, 'max_total_time' => 12],
            MetaUsageHeader::summarise(json_encode(['1' => [['call_count' => 3, 'total_cputime' => 5, 'total_time' => 12]], '2' => [['call_count' => 90]]])),
        );
        $this->assertFalse(MetaUsageHeader::summarise(null)['present']);
    }

    public function test_a_meta_webhook_is_verified_through_x_hub_signature_256(): void
    {
        app(ProviderConfigurationService::class)->save('meta', ['client_id' => 'app-1', 'client_secret' => 'the-webhook-app-secret']);
        $body = json_encode(['object' => 'ad_account', 'entry' => []]);

        $this->call('POST', '/api/v1/webhooks/ads/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'the-webhook-app-secret'),
        ], $body)->assertOk();

        $this->call('POST', '/api/v1/webhooks/ads/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'wrong'),
        ], $body)->assertStatus(401);
    }

    /** @return array<string,string> */
    private function digest(): array
    {
        return collect(['meta_candidate_connections', 'provider_configurations', 'provider_connections', 'external_accounts', 'audit_logs'])
            ->mapWithKeys(fn (string $t) => [$t => md5(json_encode(DB::table($t)->orderBy('id')->get()))])
            ->all();
    }
}
