<?php

declare(strict_types=1);

namespace App\Domains\Integrations\OAuth;

use App\Domains\Integrations\Support\PlatformHttp;
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
 * - **X** and **LinkedIn** want the client credentials in a Basic header rather than the body.
 *
 * Nothing here runs without a configured platform: `PlatformCredentials::isConfigured()` is checked
 * first and the call refuses rather than sending a request that is certain to be rejected.
 */
final class PlatformOAuth
{
    /**
     * The URL to send somebody to in order to authorise us.
     *
     * `$state` is minted and recorded by the caller; it comes back on the callback and is the only
     * thing tying a returning browser to the request that started the flow.
     */
    public function authorizationUrl(PlatformCredentials $creds, string $state): string
    {
        $this->assertConfigured($creds);

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

    /** Exchange the code a platform sent back for tokens. */
    public function exchangeCode(PlatformCredentials $creds, string $code): OAuthTokens
    {
        $this->assertConfigured($creds);

        if ($creds->platform === 'tiktok') {
            return $this->tikTokToken($creds, ['auth_code' => $code, 'grant_type' => 'auth_code']);
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

        if ($creds->platform === 'tiktok') {
            return $current; // no refresh grant exists; the token is valid until revoked
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
        // X and LinkedIn authenticate the token call itself; the others take the pair in the body.
        $usesBasicAuth = in_array($creds->platform, ['x'], true);

        $request = PlatformHttp::client($creds->platform)->asForm();

        if ($usesBasicAuth) {
            $request = $request->withBasicAuth((string) $creds->get('client_id'), (string) $creds->get('client_secret'));
        } else {
            $grant['client_id'] = $creds->get('client_id');
            $grant['client_secret'] = $creds->get('client_secret');
        }

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
            scope: isset($body['scope']) ? (string) $body['scope'] : $previous?->scope,
            raw: $body,
        );
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
