<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\OAuth\PlatformCredentials;
use Tests\TestCase;

/**
 * GA4-PLATFORM-GOOGLE-CLIENT-001 — GA4 uses the sign-in Google client unless its own keys are set; sign-in and
 * Google Ads read their own keys exactly as before; the scope and callback of GA4 do not move.
 */
final class Ga4PlatformGoogleClientTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    /** `env()` reads `$_ENV`/`$_SERVER` before `putenv`, and CI's .env carries empty values; set every reader. */
    private function setEnv(string $key, ?string $value): void
    {
        $this->saved[$key] ??= $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            return;
        }
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            if ($value === false) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
        parent::tearDown();
    }

    public function test_ga4_falls_back_to_the_sign_in_google_client_when_its_own_keys_are_absent(): void
    {
        // The config file resolves at boot; the rule under test is the file's own expression, re-evaluated here.
        $this->setEnv('GA4_CLIENT_ID', null);
        $this->setEnv('GA4_CLIENT_SECRET', null);
        $this->setEnv('GOOGLE_CLIENT_ID', '741831383514-test.apps.googleusercontent.com');
        $this->setEnv('GOOGLE_CLIENT_SECRET', 'not-a-real-secret');
        $fresh = require base_path('config/measurement_platforms.php');

        $this->assertSame('741831383514-test.apps.googleusercontent.com', $fresh['platforms']['ga4']['client_id']);
        $this->assertSame('not-a-real-secret', $fresh['platforms']['ga4']['client_secret']);
        $this->assertSame(['https://www.googleapis.com/auth/analytics.readonly'], $fresh['platforms']['ga4']['scopes']);
    }

    public function test_ga4_own_keys_still_win_when_set(): void
    {
        $this->setEnv('GA4_CLIENT_ID', 'ga4-own.apps.googleusercontent.com');
        $this->setEnv('GA4_CLIENT_SECRET', 'ga4-own-secret');
        $this->setEnv('GOOGLE_CLIENT_ID', '741831383514-test.apps.googleusercontent.com');
        $fresh = require base_path('config/measurement_platforms.php');

        $this->assertSame('ga4-own.apps.googleusercontent.com', $fresh['platforms']['ga4']['client_id']);
    }

    public function test_sign_in_and_google_ads_keep_their_own_keys_and_ga4_keeps_its_callback(): void
    {
        $this->assertSame(env('GOOGLE_CLIENT_ID'), config('services.google.client_id'));
        $this->assertSame(env('GOOGLE_ADS_CLIENT_ID'), config('ad_platforms.platforms.google.client_id'));
        $this->assertStringEndsWith('/api/v1/oauth/measurement/ga4/callback', PlatformCredentials::for('ga4')->redirectUri());
    }
}
