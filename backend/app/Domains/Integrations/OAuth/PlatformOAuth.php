<?php

declare(strict_types=1);

namespace App\Domains\Integrations\OAuth;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Support\PlatformHttp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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
    /**
     * X-PKCE-001 — a fresh code verifier, for the providers that need one.
     *
     * Null for everybody else, and driven by the CATALOGUE's `usesPkce` rather than a literal list.
     * That field used to be a declaration nothing read: the catalogue said X requires PKCE, the header
     * comment said the verifier «must survive the whole round trip», and no line of code anywhere
     * produced a challenge. Reading the declaration is what stops the two drifting apart again.
     *
     * 96 characters of `[A-Za-z0-9]`, the same shape `Identity/Services/OAuthFlow` already uses for
     * staff sign-in — comfortably inside RFC 7636's 43–128 unreserved characters.
     */
    public function codeVerifier(PlatformCredentials $creds): ?string
    {
        return ProviderCatalogue::get($creds->platform)->usesPkce ? Str::random(96) : null;
    }

    /** The S256 challenge for a verifier: base64url of its raw SHA-256, per RFC 7636. */
    public function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public function authorizationUrl(PlatformCredentials $creds, string $state, ?string $verifier = null): string
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

        /*
         * The challenge rides on the authorise URL when — and only when — a verifier was minted for
         * this flow. Sending one to a provider that does not publish PKCE would be an unrequested
         * change to five working integrations, so it is gated on the verifier and not added by hand.
         */
        if ($verifier !== null) {
            $query['code_challenge'] = $this->codeChallenge($verifier);
            $query['code_challenge_method'] = 'S256';
        }

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
    public function exchangeCode(PlatformCredentials $creds, string $code, ?string $verifier = null): OAuthTokens
    {
        $this->assertConfigured($creds);

        // The documented body is exactly {app_id, secret, auth_code}. `grant_type` was OAuth
        // vocabulary TikTok never asked for, and an undocumented field is not worth discovering on a
        // customer's first connection.
        if ($creds->platform === 'tiktok') {
            return $this->tikTokToken($creds, ['auth_code' => $code]);
        }

        /*
         * X-PKCE-001 — fail CLOSED when a PKCE provider has no verifier to present.
         *
         * The alternative is to exchange anyway, which sends X a request it is obliged to reject, and
         * the customer is then shown X's refusal as though the PLATFORM were broken. That is the exact
         * failure mode this audit exists to remove: an error message that names the wrong culprit.
         *
         * It should be unreachable — `start` mints the verifier and the state carries it — so reaching
         * it means a state was minted by code that predates this, or by something that is not `start`.
         * Both are worth saying out loud rather than laundering into a platform error.
         */
        if (ProviderCatalogue::get($creds->platform)->usesPkce && $verifier === null) {
            throw new RuntimeException(
                $creds->label().' requires PKCE, and this authorisation carried no code verifier. '
                    .'Start the connection again.',
            );
        }

        return $this->standardToken($creds, array_filter([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $creds->redirectUri(),
            'code_verifier' => $verifier,
        ], static fn ($v) => $v !== null));
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
