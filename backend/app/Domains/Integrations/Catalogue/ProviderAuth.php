<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Catalogue;

/**
 * INTEG-AUTH-KIND-001 — how a provider is authorised, which is not the same question for all of them.
 *
 * ## Why this had to become a value
 *
 * Every provider in this catalogue was an OAuth provider, so OAuth was not a property — it was the
 * shape of the code. `ProviderDefinition` carries scopes, a PKCE flag, a refresh flag and a redirect
 * URI; `AdPlatformOAuthController` begins every connection by sending a browser to a consent screen;
 * the wizard's first step is called «تسجيل الدخول».
 *
 * OpenAI's Advertiser API has none of that. A key is issued in the advertiser's own console, it is
 * scoped to ONE ad account, and it is presented as a bearer token. There is no consent screen to
 * send anybody to, no scopes to request, no refresh token to rotate and no callback to register.
 *
 * Giving it a fake consent screen would be the invention `ProviderHierarchy` already refuses one
 * level down: a step that exists because the interface has a slot for it, populated with something
 * derived to fill the box. So the authorisation METHOD is a property of the provider, the flow asks
 * for it, and the step that does not apply is not drawn.
 */
enum ProviderAuth: string
{
    /** A consent screen, an authorization code, and tokens this product refreshes. */
    case OAuth = 'oauth';

    /**
     * A key the advertiser creates in the provider's own console and pastes here once.
     *
     * The product never sees it again after it is stored: it is written to the encrypted vault, and
     * every read of it happens server-side on the way to the provider.
     */
    case ApiKey = 'api_key';

    /** Whether a connection of this kind begins by sending the reader to the provider. */
    public function redirectsToProvider(): bool
    {
        return $this === self::OAuth;
    }

    /** What the first step of the wizard is called, in the reader's language. */
    public function stepLabel(bool $ar): string
    {
        return match ($this) {
            self::OAuth => $ar ? 'تسجيل الدخول' : 'Sign in',
            self::ApiKey => $ar ? 'ربط الحساب' : 'Connect the account',
        };
    }
}
