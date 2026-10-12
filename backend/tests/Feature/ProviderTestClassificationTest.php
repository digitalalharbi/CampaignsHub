<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Enums\ProviderSetupState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PROVIDER-TEST-001 — «اختبار الإعداد» reads each provider's real answer to a deliberately invalid
 * authorisation code, and says one of three things: the app was recognised (pass), the credentials
 * were refused (fail — a configuration error), or the answer did not say (inconclusive — a warning,
 * never a red error). A key-based provider has no platform credentials to test at all.
 */
final class ProviderTestClassificationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::create(['name' => 'Platform', 'email' => 'p@platform.test', 'password' => 'secret123']);
        $this->owner->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();
    }

    private function configure(string $provider): void
    {
        $values = [];
        foreach (ProviderCatalogue::get($provider)->requiredKeys() as $key) {
            $values[$key] = "test-value-{$key}";
        }
        app(ProviderConfigurationService::class)->save($provider, $values);
    }

    private function runTest(string $provider): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/admin/settings/integrations/providers/{$provider}/test")
            ->assertOk();
    }

    /** @return array<string, array{string, array<string,mixed>, int, string, string}> */
    public static function answers(): array
    {
        return [
            // TikTok — documented return codes (return-codes-appendix v1.3), HTTP 200 with `code`.
            'tiktok 40110 invalid auth code → recognised' => ['tiktok', ['code' => 40110, 'message' => 'The auth_code is invalid.'], 200, 'passed', ProviderSetupState::CredentialsVerified->value],
            'tiktok 40002 parameter error on the code → recognised' => ['tiktok', ['code' => 40002, 'message' => 'The input data is invalid for the associated parameter.'], 200, 'passed', ProviderSetupState::CredentialsVerified->value],
            'tiktok 40101 secret and app id do not match → refused' => ['tiktok', ['code' => 40101, 'message' => 'Invalid parameter values for authentication.'], 200, 'failed', ProviderSetupState::ConfigurationError->value],
            'tiktok 40113 app blocked or missing → refused' => ['tiktok', ['code' => 40113, 'message' => 'The app has been blocked or does not exist.'], 200, 'failed', ProviderSetupState::ConfigurationError->value],
            'tiktok unknown code → inconclusive' => ['tiktok', ['code' => 50000, 'message' => 'System error.'], 200, 'inconclusive', ProviderSetupState::ReadyToConnect->value],
            // LinkedIn — its normal refusal of a fake code with valid credentials, and of bad credentials.
            'linkedin code not found → recognised' => ['linkedin', ['error' => 'invalid_request', 'error_description' => 'Unable to retrieve access token: authorization code not found'], 400, 'passed', ProviderSetupState::CredentialsVerified->value],
            'linkedin invalid_client → refused' => ['linkedin', ['error' => 'invalid_client', 'error_description' => 'Client authentication failed'], 401, 'failed', ProviderSetupState::ConfigurationError->value],
            'linkedin unreadable answer → inconclusive' => ['linkedin', ['message' => 'Something went wrong'], 500, 'inconclusive', ProviderSetupState::ReadyToConnect->value],
            // Google / GA4 — the same OAuth token endpoint semantics.
            'google invalid_grant → recognised' => ['google', ['error' => 'invalid_grant', 'error_description' => 'Malformed auth code.'], 400, 'passed', ProviderSetupState::CredentialsVerified->value],
            'google invalid_client → refused' => ['google', ['error' => 'invalid_client', 'error_description' => 'The OAuth client was not found.'], 401, 'failed', ProviderSetupState::ConfigurationError->value],
            'ga4 invalid_grant → recognised' => ['ga4', ['error' => 'invalid_grant', 'error_description' => 'Malformed auth code.'], 400, 'passed', ProviderSetupState::CredentialsVerified->value],
            'ga4 unreadable answer → inconclusive' => ['ga4', ['error' => 'temporarily_unavailable'], 503, 'inconclusive', ProviderSetupState::ReadyToConnect->value],
        ];
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('answers')]
    public function test_each_providers_answer_is_classified(string $provider, array $body, int $status, string $outcome, string $state): void
    {
        $this->configure($provider);
        Http::fake(['*' => Http::response($body, $status)]);

        $res = $this->runTest($provider);

        $this->assertSame($outcome, $res->json('data.outcome'));
        $this->assertSame($outcome === 'passed', $res->json('data.passed'));
        $this->assertSame($outcome, $res->json('data.last_test_status'));
        $this->assertSame($state, $res->json('data.state'), 'an inconclusive answer is not a configuration error');
        // A real token endpoint was called — GA4 used to post to an empty URL (a 5xx is retried, so not counted).
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://'));
    }

    /** OpenAI is connected with each advertiser's own API key: nothing to test, nothing indexed, no crash. */
    public function test_a_key_based_provider_needs_no_platform_credentials(): void
    {
        Http::fake();

        $res = $this->runTest('openai_ads');

        $this->assertSame('not_required', $res->json('data.outcome'));
        $this->assertStringContainsString('No platform credentials are required', (string) $res->json('data.message'));
        $this->assertStringNotContainsString('client_id', (string) $res->json('data.message'));
        Http::assertNothingSent();
    }
}
