<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Support\PlatformHttp;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * INTEG-PROVIDER-IDENTITY-001 — WHICH provider login a connection was authorised with.
 *
 * `created_by` is the CampaignsHub user. This is the Google / Meta / Snapchat / LinkedIn account that
 * granted access, asked of the provider itself with the token we already hold. Only endpoints the
 * provider's own documentation states are called; a provider with no documented identity call here
 * (TikTok, X) records nothing and the interface says so rather than showing the workspace email.
 *
 *  - Google   GET https://openidconnect.googleapis.com/v1/userinfo — `email` needs the `email` scope, `name`
 *             the `profile` scope. https://developers.google.com/identity/openid-connect/openid-connect
 *  - Meta     GET {graph}/me?fields=id,name,email — `name` is public_profile; `email` is returned only when
 *             the `email` permission was granted and a valid address exists (not requested today, so name only).
 *             https://developers.facebook.com/docs/graph-api/reference/user/
 *  - Snapchat GET https://adsapi.snapchat.com/v1/me — «the Snapchat user represented by the access token»;
 *             the documented sample carries `display_name` and `email`.
 *             https://developers.snap.com/api/marketing-api/Ads-API/user
 *  - LinkedIn GET https://api.linkedin.com/v2/me — name only, needs r_liteprofile or r_basicprofile (the latter
 *             is already requested); no email (that is the OpenID `email` scope, not requested).
 *             https://learn.microsoft.com/en-us/linkedin/shared/integrations/people/profile-api
 *  - API key  (OpenAI Ads) has no person behind it: the key's ad account name is shown.
 *
 * Nothing here ever prints or stores a token, and a refusal is returned as a word, never retried in a loop.
 */
final class ProviderIdentity
{
    public const RECORDED = 'recorded';

    public const UNSUPPORTED = 'unsupported';

    public const REFUSED = 'refused';

    public const NO_TOKEN = 'no_token';

    /** @var list<string> */
    private const SUPPORTED = ['google', 'meta', 'snapchat', 'linkedin', 'openai_ads'];

    public function __construct(private readonly TokenVault $vault) {}

    /** Connect-time capture with the tokens just issued. Never throws: identity is a nicety, not a gate. */
    public function capture(ProviderConnection $connection, ?OAuthTokens $tokens = null): string
    {
        try {
            return $this->resolve($connection, $tokens ?? $this->vault->stored($connection));
        } catch (Throwable) {
            return self::NO_TOKEN;
        }
    }

    /** Backfill path: the stored token goes through the vault (refreshing it when due). */
    public function refresh(ProviderConnection $connection): string
    {
        if (! in_array((string) $connection->provider, self::SUPPORTED, true)) {
            return self::UNSUPPORTED;
        }

        try {
            $tokens = $connection->provider === 'openai_ads' ? $this->vault->stored($connection) : $this->vault->fresh($connection);
        } catch (Throwable) {
            return self::NO_TOKEN;
        }

        return $this->resolve($connection, $tokens);
    }

    private function resolve(ProviderConnection $connection, OAuthTokens $tokens): string
    {
        $provider = (string) $connection->provider;

        if (! in_array($provider, self::SUPPORTED, true)) {
            return self::UNSUPPORTED;
        }

        try {
            $found = $provider === 'openai_ads' ? $this->keyAccountName($connection) : $this->ask($provider, $connection, $tokens);
        } catch (Throwable) {
            return self::REFUSED;
        }

        if ($found === null) {
            return self::REFUSED;
        }

        $connection->forceFill([
            'provider_identity_label' => $found['label'],
            'provider_identity_email' => $found['email'],
            'provider_identity_fetched_at' => Carbon::now(),
        ])->save();

        return self::RECORDED;
    }

    /** @return array{label:?string,email:?string}|null */
    private function ask(string $provider, ProviderConnection $connection, OAuthTokens $tokens): ?array
    {
        $http = PlatformHttp::client($provider)->withToken($tokens->accessToken);
        $base = PlatformCredentials::forConnection($connection)->apiBase();

        $response = match ($provider) {
            'google' => $http->get('https://openidconnect.googleapis.com/v1/userinfo'),
            'meta' => $http->get($base.'/me', ['fields' => 'id,name,email']),
            'snapchat' => $http->get($base.'/me'),
            'linkedin' => $http->get('https://api.linkedin.com/v2/me'),
            default => null,
        };

        if ($response === null || ! $response->successful()) {
            return null;
        }

        /** @var array<string,mixed> $body */
        $body = (array) $response->json();
        /** @var array<string,mixed> $me */
        $me = is_array($body['me'] ?? null) ? $body['me'] : $body;

        $email = $this->clean($me['email'] ?? null);
        $label = match ($provider) {
            'snapchat' => $this->clean($me['display_name'] ?? null),
            'linkedin' => $this->clean(trim((string) ($me['localizedFirstName'] ?? '').' '.(string) ($me['localizedLastName'] ?? ''))),
            default => $this->clean($me['name'] ?? null),
        };

        if ($email === null && $label === null) {
            return null;
        }

        return ['label' => $label, 'email' => $email];
    }

    /** @return array{label:?string,email:?string}|null */
    private function keyAccountName(ProviderConnection $connection): ?array
    {
        $name = ExternalAccount::withoutGlobalScopes()
            ->where('provider_connection_id', $connection->getKey())
            ->where('account_type', 'ad_account')
            ->orderBy('created_at')
            ->value('name');

        $name = $this->clean($name);

        return $name === null ? null : ['label' => $name, 'email' => null];
    }

    private function clean(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : mb_substr($text, 0, 190);
    }
}
