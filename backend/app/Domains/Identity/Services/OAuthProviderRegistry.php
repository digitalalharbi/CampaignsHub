<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

/**
 * Which social sign-in providers exist, and which of them can actually work (LOGIN-004).
 *
 * The distinction is the entire reason this class exists. A provider with no client credentials is
 * not "a button that fails when you press it" — it is a feature that has not been set up, and the
 * sign-in page has to say so. Rendering an enabled Google button with no `GOOGLE_CLIENT_ID` behind
 * it produces a redirect to an error page nobody can explain, and it tells the visitor the platform
 * supports something it does not.
 *
 * `Live` and `AwaitingCredentials` are therefore reported to the client, and the button is inert in
 * the second case with the reason on it.
 */
final class OAuthProviderRegistry
{
    public const AWAITING_CREDENTIALS = 'awaiting_credentials';

    public const LIVE = 'live';

    /**
     * Provider metadata. The endpoints are the providers' published ones; nothing here is a secret.
     *
     * @var array<string, array{label_en: string, label_ar: string, authorize: string, token: string, scopes: string, response_mode?: string}>
     */
    private const PROVIDERS = [
        'google' => [
            'label_en' => 'Google',
            'label_ar' => 'Google',
            'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token' => 'https://oauth2.googleapis.com/token',
            'scopes' => 'openid email profile',
        ],
        'apple' => [
            'label_en' => 'Apple',
            'label_ar' => 'Apple',
            'authorize' => 'https://appleid.apple.com/auth/authorize',
            'token' => 'https://appleid.apple.com/auth/token',
            'scopes' => 'name email',
            // Apple returns the response as a POST form when scopes are requested.
            'response_mode' => 'form_post',
        ],
    ];

    /** @return list<string> */
    public function names(): array
    {
        return array_keys(self::PROVIDERS);
    }

    public function knows(string $provider): bool
    {
        return array_key_exists($provider, self::PROVIDERS);
    }

    /** @return array<string, mixed>|null */
    public function definition(string $provider): ?array
    {
        return self::PROVIDERS[$provider] ?? null;
    }

    /**
     * Configured means BOTH halves are present. A client id alone gets you as far as the provider's
     * consent screen and then fails at the token exchange — worse than not offering it, because the
     * failure happens after the visitor has already handed over their credentials.
     */
    public function isConfigured(string $provider): bool
    {
        if (! $this->knows($provider)) {
            return false;
        }

        $config = (array) config("services.{$provider}", []);
        $id = $config['client_id'] ?? null;
        // Apple signs a JWT with a private key instead of sending a static secret.
        $secret = $config['client_secret'] ?? ($config['private_key'] ?? null);

        return is_string($id) && $id !== '' && is_string($secret) && $secret !== '';
    }

    public function status(string $provider): string
    {
        return $this->isConfigured($provider) ? self::LIVE : self::AWAITING_CREDENTIALS;
    }

    /**
     * What the sign-in page renders. Every known provider is listed whatever its state, so the page
     * shows the same layout in every environment and the difference is stated rather than hidden.
     *
     * @return list<array<string, mixed>>
     */
    public function forClient(): array
    {
        return array_values(array_map(function (string $name): array {
            $definition = self::PROVIDERS[$name];

            return [
                'provider' => $name,
                'label' => ['en' => $definition['label_en'], 'ar' => $definition['label_ar']],
                'status' => $this->status($name),
                'available' => $this->isConfigured($name),
            ];
        }, $this->names()));
    }
}
