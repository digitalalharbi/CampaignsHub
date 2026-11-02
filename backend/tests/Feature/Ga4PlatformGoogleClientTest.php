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
    public function test_ga4_falls_back_to_the_sign_in_google_client_when_its_own_keys_are_absent(): void
    {
        config(['measurement_platforms.platforms.ga4.client_id' => null, 'measurement_platforms.platforms.ga4.client_secret' => null]);
        // The config file resolves at boot; the rule under test is the file's own expression, re-evaluated here.
        putenv('GA4_CLIENT_ID');
        putenv('GA4_CLIENT_SECRET');
        putenv('GOOGLE_CLIENT_ID=741831383514-test.apps.googleusercontent.com');
        putenv('GOOGLE_CLIENT_SECRET=not-a-real-secret');
        $fresh = require base_path('config/measurement_platforms.php');

        $this->assertSame('741831383514-test.apps.googleusercontent.com', $fresh['platforms']['ga4']['client_id']);
        $this->assertSame('not-a-real-secret', $fresh['platforms']['ga4']['client_secret']);
        $this->assertSame(['https://www.googleapis.com/auth/analytics.readonly'], $fresh['platforms']['ga4']['scopes']);
    }

    public function test_ga4_own_keys_still_win_when_set(): void
    {
        putenv('GA4_CLIENT_ID=ga4-own.apps.googleusercontent.com');
        putenv('GA4_CLIENT_SECRET=ga4-own-secret');
        putenv('GOOGLE_CLIENT_ID=741831383514-test.apps.googleusercontent.com');
        $fresh = require base_path('config/measurement_platforms.php');

        $this->assertSame('ga4-own.apps.googleusercontent.com', $fresh['platforms']['ga4']['client_id']);
        putenv('GA4_CLIENT_ID');
        putenv('GA4_CLIENT_SECRET');
    }

    public function test_sign_in_and_google_ads_keep_their_own_keys_and_ga4_keeps_its_callback(): void
    {
        $this->assertSame(env('GOOGLE_CLIENT_ID'), config('services.google.client_id'));
        $this->assertSame(env('GOOGLE_ADS_CLIENT_ID'), config('ad_platforms.platforms.google.client_id'));
        $this->assertStringEndsWith('/api/v1/oauth/measurement/ga4/callback', PlatformCredentials::for('ga4')->redirectUri());
    }
}
