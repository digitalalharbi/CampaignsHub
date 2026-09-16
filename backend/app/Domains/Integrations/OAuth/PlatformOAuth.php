<?php

declare(strict_types=1);

namespace App\Domains\Integrations\OAuth;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Support\PlatformHttp;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * INTEG-OAUTH-001 — the authorization-code flow, once, for all six platforms.
 *
 * Three jobs: build the URL the customer is sent to, exchange the returned code for tokens, and
 * refresh those tokens before they die. Each platform bends the standard somewhere, and every bend is
 * handled here rather than in six connectors:
 *
 * - **TikTok** does not use the OAuth parameter names at all. Its authorise call wants `app_id` and
 *   `redirect_uri`, its token call wants `app_id`/`secret`/`auth_code` as JSON, and the answer arrives
 *   inside a `data` envelope with a `code` field that is 0 on success. A non-zero `code` with HTTP 200
 *   is a FAILURE, and reading only the HTTP status would store an empty token as a success.
 * - **Meta** returns a short-lived token from the code exchange and has no refresh token; the long-lived
 *   exchange is a second call against the same endpoint with `grant_type=fb_exchange_token`.
 * - **X** is not OAuth 2.0 at all (X-OAUTH1-001). Its Ads API takes OAuth 1.0a only: a request token,
 *   the user's authorisation, then an access token AND a token secret, with every call signed. That
 *   flow lives in the second half of this class and shares nothing with the first but the refusal to
 *   run unconfigured.
 *
 * Nothing here runs without a configured platform: `PlatformCredentials::isConfigured()` is checked
 * first and the call refuses rather than sending a request that is certain to be rejected.
 */
final class PlatformOAuth
{
    /**
     * The URL to send somebody to in order to authorise us — for the OAuth 2.0 providers.
     *
     * `$state` is minted and recorded by the caller; it comes back on the callback and is the only
     * thing tying a returning browser to the request that started the flow.
     *
     * X is refused here rather than handed a URL. Its authorise step needs a request token that only
     * exists after a signed call to X, so there is no URL to build from a state alone — see
     * `requestToken()` and `oauth1AuthorizeUrl()`.
     */
    public function authorizationUrl(PlatformCredentials $creds, string $state): string
    {
        $this->assertConfigured($creds);
        $this->assertOAuth2($creds);

        $query = match ($creds->platform) {
            // TikTok: app_id/state/redirect_uri, and no response_type or scope.
            'tiktok' => [
                'app_id' => $creds->get('client_id'),
                'state' => $state,
                'redirect_uri' => $creds->redirectUri(),
            ],
            default => array_filter([
                'client_id' => $creds->get('client_id'),
                'redirect_uri' => $creds->redirectUri(),
                'response_type' => 'code',
                'state' => $state,
                'scope' => $creds->scopes() === [] ? null : implode(
                    // Snapchat and Meta take a comma-separated list; the rest take spaces.
                    in_array($creds->platform, ['meta', 'snapchat'], true) ? ',' : ' ',
                    $creds->scopes(),
                ),
                // Google only issues a refresh token when both are asked for, and only on first consent.
                'access_type' => $creds->platform === 'google' ? 'offline' : null,
                'prompt' => $creds->platform === 'google' ? 'consent' : null,
            ], static fn ($v) => $v !== null),
        };

        return $creds->authorizeUrl().'?'.http_build_query($query);
    }

    /**
     * TIKTOK-AUTH-001 — the query parameter on the callback that actually carries the exchangeable code.
     *
     * Every provider here but one calls it `code`. TikTok's documented redirect carries BOTH, with
     * DIFFERENT values:
     *
     * ```
     * …?state=…&code=3c6dc21d…&auth_code=1234c21d…&id=1701890905779201
     * ```
     *
     * and its authorization page states, of that example, that the code to extract is the `auth_code`
     * one. Reading `code` for everybody meant we posted TikTok the value it does not accept, and every
     * TikTok connection failed at the first exchange — a defect no fixture that sends a single
     * parameter can see, because it cannot tell the two apart.
     *
     * There is deliberately no fallback to `code` for TikTok. Falling back would post the value now
     * known to be wrong and report TikTok's refusal to the customer as a platform outage.
     */
    public function callbackCodeParameter(PlatformCredentials $creds): string
    {
        return $creds->platform === 'tiktok' ? 'auth_code' : 'code';
    }

    /** Exchange the code a platform sent back for tokens. */
    public function exchangeCode(PlatformCredentials $creds, string $code): OAuthTokens
    {
        $this->assertConfigured($creds);
        $this->assertOAuth2($creds);

        // The documented body is exactly {app_id, secret, auth_code}. `grant_type` was OAuth
        // vocabulary TikTok never asked for, and an undocumented field is not worth discovering on a
        // customer's first connection.
        if ($creds->platform === 'tiktok') {
            return $this->tikTokToken($creds, ['auth_code' => $code]);
        }

        return $this->standardToken($creds, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $creds->redirectUri(),
        ]);
    }

    /**
     * Trade a refresh token for a fresh access token.
     *
     * Two platforms cannot do this and say so plainly rather than failing obscurely: TikTok's business
     * tokens do not expire and have no refresh grant, and Meta issues long-lived tokens that are
     * extended, not refreshed. A caller that treats "cannot refresh" as an error would mark a perfectly
     * healthy connection as broken every hour.
     */
    public function refresh(PlatformCredentials $creds, OAuthTokens $current): OAuthTokens
    {
        $this->assertConfigured($creds);

        // TikTok's business tokens have no refresh grant, and an OAuth 1.0a token (X) does not expire:
        // both are valid until revoked.
        if ($creds->platform === 'tiktok' || ProviderCatalogue::get($creds->platform)->usesOAuth1()) {
            return $current;
        }

        if ($creds->platform === 'meta') {
            return $this->standardToken($creds, [
                'grant_type' => 'fb_exchange_token',
                'fb_exchange_token' => $current->accessToken,
            ], $current);
        }

        if ($current->refreshToken === null) {
            throw new RuntimeException($creds->label().' has no refresh token stored; the customer must authorise again.');
        }

        return $this->standardToken($creds, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $current->refreshToken,
        ], $current);
    }

    /**
     * @param  array<string,mixed>  $grant
     * @param  OAuthTokens|null  $previous  carried so a platform that omits the refresh token on a
     *                                      refresh (Google always does) does not lose the one we hold
     */
    private function standardToken(PlatformCredentials $creds, array $grant, ?OAuthTokens $previous = null): OAuthTokens
    {
        $request = PlatformHttp::client($creds->platform)->asForm();

        $grant['client_id'] = $creds->get('client_id');
        $grant['client_secret'] = $creds->get('client_secret');

        $response = $request->post($creds->tokenUrl(), $grant);

        if ($response->failed()) {
            throw new RuntimeException(
                $creds->label().' refused the token request ('.$response->status().'): '.$this->briefly($response->body()),
            );
        }

        /** @var array<string,mixed> $body */
        $body = $response->json() ?? [];

        $tokens = $this->tokensFrom($creds, $body, $previous);

        /*
         * COMMERCE-001 — Zid hands back TWO credentials, and one of them is not called a token.
         *
         * `access_token` goes in `Authorization: Bearer`, and a separate `authorization` value goes in
         * `X-Manager-Token`. A call carrying only the first is refused by every endpoint. It survives
         * inside `raw`, but a token set arriving WITHOUT it is a connection that will exchange
         * perfectly and then fail on its first read — which is the exact «connected, and your numbers
         * are zero» state this product refuses to enter. So it is checked here, at the one moment the
         * answer is in front of us.
         */
        if ($creds->platform === 'zid' && ! isset($tokens->raw['authorization'])) {
            throw new RuntimeException(
                $creds->label().' returned an access token without the manager token its API also requires.',
            );
        }

        return $tokens;
    }

    /**
     * TikTok's token endpoint: JSON in, `{ code: 0, data: { … } }` out.
     *
     * @param  array<string,mixed>  $grant
     */
    private function tikTokToken(PlatformCredentials $creds, array $grant): OAuthTokens
    {
        $response = PlatformHttp::client($creds->platform)->post($creds->tokenUrl(), [
            ...$grant,
            'app_id' => $creds->get('client_id'),
            'secret' => $creds->get('client_secret'),
        ]);

        if ($response->failed()) {
            throw new RuntimeException($creds->label().' refused the token request ('.$response->status().').');
        }

        /** @var array<string,mixed> $body */
        $body = $response->json() ?? [];

        // A 200 with a non-zero `code` is TikTok saying no. Reading the HTTP status alone stores an
        // empty access token and calls the connection live.
        if ((int) ($body['code'] ?? -1) !== 0) {
            throw new RuntimeException(
                $creds->label().' refused the token request: '.(string) ($body['message'] ?? 'no reason given'),
            );
        }

        /** @var array<string,mixed> $data */
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        return $this->tokensFrom($creds, $data);
    }

    /** @param array<string,mixed> $body */
    private function tokensFrom(PlatformCredentials $creds, array $body, ?OAuthTokens $previous = null): OAuthTokens
    {
        $accessToken = (string) ($body['access_token'] ?? '');

        if ($accessToken === '') {
            throw new RuntimeException($creds->label().' returned no access token.');
        }

        $expiresIn = $body['expires_in'] ?? null;

        return new OAuthTokens(
            accessToken: $accessToken,
            // Google omits the refresh token on every refresh; keeping the previous one is what makes
            // the connection survive its second hour.
            refreshToken: isset($body['refresh_token']) && $body['refresh_token'] !== null
                ? (string) $body['refresh_token']
                : $previous?->refreshToken,
            expiresAt: is_numeric($expiresIn) ? Carbon::now()->addSeconds((int) $expiresIn) : null,
            scope: $this->scopeFrom($body) ?? $previous?->scope,
            raw: $body,
        );
    }

    /**
     * TIKTOK-SCOPE-001 — a granted scope is not always a string.
     *
     * This was `(string) $body['scope']`, which is right for the OAuth providers: they answer with a
     * delimited string. TikTok answers with `scope: number[]` — a list of numeric permission ids, as
     * its authentication reference documents and its own example shows (`"scope": [4]`). Casting an
     * array to string in PHP 8 raises «Array to string conversion», and it did so inside the token
     * exchange, so the whole callback ended in `outcome=failed` with that message shown to a customer.
     *
     * The scope granted is worth keeping — TikTok grants what the ADVERTISER approved, which is not
     * necessarily everything the app asked for — so it is joined rather than dropped. An empty or
     * absent scope yields null so the previous token's scope survives a refresh, exactly as before.
     *
     * @param  array<string,mixed>  $body
     */
    private function scopeFrom(array $body): ?string
    {
        $scope = $body['scope'] ?? null;

        if (is_array($scope)) {
            $scope = implode(' ', array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $scope));
        }

        return is_scalar($scope) && (string) $scope !== '' ? (string) $scope : null;
    }

    // ── OAuth 1.0a (X Ads) ────────────────────────────────────────────────────────────────────

    /**
     * X-OAUTH1-001 — leg one: ask X for a request token bound to our callback.
     *
     * Signed with the app's consumer pair alone; there is no user yet. X answers form-encoded, and
     * `oauth_callback_confirmed=true` is the only proof it accepted the callback we named — a request
     * token issued without it would send the customer to an authorise page whose «Allow» leads nowhere,
     * so it is refused here rather than discovered there.
     *
     * @return array{token:string, secret:string}
     */
    public function requestToken(PlatformCredentials $creds): array
    {
        $this->assertConfigured($creds);
        $this->assertOAuth1($creds);

        $response = PlatformHttp::client($creds->platform)
            ->withMiddleware(new OAuth1Signer(
                consumerKey: (string) $creds->get('consumer_key'),
                consumerSecret: (string) $creds->get('consumer_secret'),
                callback: $creds->redirectUri(),
            ))
            ->post((string) $creds->get('request_token_url'));

        $body = $this->formBody($creds, $response, 'request token');

        if (($body['oauth_callback_confirmed'] ?? null) !== 'true') {
            throw new RuntimeException($creds->label().' issued a request token without confirming the callback URI; '
                .'register the callback URI exactly in the app\'s User authentication settings.');
        }

        return ['token' => $body['oauth_token'], 'secret' => $body['oauth_token_secret']];
    }

    /** Leg two: where the customer authorises. The request token is the only parameter X reads. */
    public function oauth1AuthorizeUrl(PlatformCredentials $creds, string $requestToken): string
    {
        $this->assertOAuth1($creds);

        return $creds->authorizeUrl().'?'.http_build_query(['oauth_token' => $requestToken]);
    }

    /**
     * Leg three: trade the authorised request token and its verifier for the user's access token.
     *
     * Signed with the consumer pair AND the request token's secret, which never left this server —
     * that is what makes a callback carrying somebody else's `oauth_token` worthless on its own.
     * The token pair comes back with the X user id and handle, which are kept as non-secret facts.
     */
    public function exchangeVerifier(PlatformCredentials $creds, string $requestToken, string $requestTokenSecret, string $verifier): OAuthTokens
    {
        $this->assertConfigured($creds);
        $this->assertOAuth1($creds);

        $response = PlatformHttp::client($creds->platform)
            ->withMiddleware(new OAuth1Signer(
                consumerKey: (string) $creds->get('consumer_key'),
                consumerSecret: (string) $creds->get('consumer_secret'),
                token: $requestToken,
                tokenSecret: $requestTokenSecret,
                verifier: $verifier,
            ))
            ->post($creds->tokenUrl());

        $body = $this->formBody($creds, $response, 'access token');

        return new OAuthTokens(
            accessToken: $body['oauth_token'],
            // No refresh token and no expiry: an OAuth 1.0a token is valid until it is revoked.
            raw: array_filter([
                'user_id' => $body['user_id'] ?? null,
                'screen_name' => $body['screen_name'] ?? null,
            ], static fn ($v) => $v !== null && $v !== ''),
            tokenSecret: $body['oauth_token_secret'],
        );
    }

    /**
     * A form-encoded OAuth 1.0a answer that carries a token pair, or the provider's own refusal.
     *
     * @return array<string,string>
     */
    private function formBody(PlatformCredentials $creds, Response $response, string $what): array
    {
        if ($response->failed()) {
            throw new RuntimeException(
                $creds->label()." refused the {$what} request (".$response->status().'): '.$this->briefly($response->body()),
            );
        }

        parse_str($response->body(), $parsed);

        $body = array_filter($parsed, static fn ($v, $k) => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH);

        if (($body['oauth_token'] ?? '') === '' || ($body['oauth_token_secret'] ?? '') === '') {
            throw new RuntimeException($creds->label()." returned no {$what}.");
        }

        /** @var array<string,string> $body */
        return $body;
    }

    private function assertOAuth1(PlatformCredentials $creds): void
    {
        if (! ProviderCatalogue::get($creds->platform)->usesOAuth1()) {
            throw new RuntimeException($creds->label().' does not use OAuth 1.0a.');
        }
    }

    private function assertOAuth2(PlatformCredentials $creds): void
    {
        if (ProviderCatalogue::get($creds->platform)->usesOAuth1()) {
            throw new RuntimeException(
                $creds->label().' uses OAuth 1.0a: its authorisation starts with a signed request token, not an authorisation code.',
            );
        }
    }

    private function assertConfigured(PlatformCredentials $creds): void
    {
        if (! $creds->isConfigured()) {
            throw new RuntimeException(
                $creds->label().' is awaiting credentials — missing: '.implode(', ', $creds->missing()).'.',
            );
        }
    }

    private function briefly(string $body): string
    {
        return mb_substr(trim($body), 0, 200);
    }
}
