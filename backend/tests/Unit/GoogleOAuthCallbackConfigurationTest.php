<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Identity\Services\OAuthFlow;
use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GoogleOAuthCallbackConfigurationTest extends TestCase
{
    #[Test]
    public function production_template_uses_the_exact_google_login_callback(): void
    {
        $expected = 'https://api.campaignshub.io/api/v1/auth/oauth/google/callback';
        $template = (string) file_get_contents(base_path('../deploy/backend.production.env.example'));

        $this->assertStringContainsString('GOOGLE_REDIRECT_URI='.$expected, $template);
        $this->assertStringContainsString('AD_PLATFORM_REDIRECT_BASE=https://api.campaignshub.io', $template);

        config([
            'app.url' => 'https://api.campaignshub.io',
            'services.google.redirect' => $expected,
        ]);
        $this->assertSame($expected, app(OAuthFlow::class)->redirectUri('google'));

        config(['ad_platforms.redirect_base' => 'https://api.campaignshub.io']);
        $this->assertSame(
            'https://api.campaignshub.io/api/v1/oauth/ads/google/callback',
            ProviderCatalogue::get('google')->redirectUri(),
        );
    }
}
