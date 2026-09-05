<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Configuration;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\ProviderKind;
use App\Domains\Integrations\Support\PlatformHttp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * PROVCFG-001 — "Test configuration": one real round trip, and a scrupulously narrow claim about it.
 *
 * ## What can honestly be tested before ANY customer has authorised
 *
 * Nothing about an ad account. There is no ad account yet — the operator is configuring the app this
 * platform registered with the provider, and no merchant or advertiser has consented to anything. So
 * the only question a system-level test can ask is: **does the provider recognise this client id and
 * secret as one of its apps?**
 *
 * That question has a real answer, and asking it is a genuine HTTP request. We present the client
 * credentials at the provider's token endpoint alongside a grant that cannot succeed. Every OAuth 2
 * server distinguishes the two failures, and the distinction is the whole test:
 *
 * - `invalid_client` / HTTP 401 → **the app is not recognised**. The id or the secret is wrong, or the
 *   app was deleted. This is a configuration error and is recorded as one.
 * - `invalid_grant` / any other refusal that names the CODE → **the app is recognised**; the provider
 *   read our credentials, accepted them, and then correctly rejected the deliberately invalid code.
 *
 * ## The direction of doubt is fixed, deliberately
 *
 * A refusal we cannot positively identify as "your app is fine, your code is not" is recorded as
 * FAILED with the provider's own words attached. Erring the other way — calling an ambiguous answer a
 * pass — is what produces a green light on a configuration that will fail for every customer, which is
 * the exact failure this whole layer exists to prevent.
 *
 * ## What a pass does NOT mean, and the message says so
 *
 * Not that the app was approved for the scopes it asks for. Not that a developer token was granted.
 * Not that any advertiser has access. Those are proven only when a real person authorises and an
 * account listing returns — which is what `AdPlatformOAuthController` requires before it will write
 * the word "connected" against a workspace. The two are separate states about separate things and the
 * product never conflates them.
 */
final class ProviderProbe
{
    public function __construct(private readonly ProviderConfigurationService $settings) {}

    /**
     * @return array{passed: bool, message: string}
     */
    public function run(string $provider): array
    {
        $definition = ProviderCatalogue::get($provider);
        $missing = $this->settings->missing($definition->key);

        if ($missing !== []) {
            // No request is made. "We could not reach the provider" and "you have not finished the
            // form" are different problems with different fixes, and sending the request would report
            // the first when the truth is the second.
            return [
                'passed' => false,
                'message' => 'Not sent: still missing '.implode(', ', $missing).'.',
            ];
        }

        try {
            $response = $this->probe($definition->key);
        } catch (ConnectionException $e) {
            return ['passed' => false, 'message' => 'Could not reach the provider: '.$this->scrub($provider, $e->getMessage())];
        } catch (Throwable $e) {
            return ['passed' => false, 'message' => $this->scrub($provider, $e->getMessage())];
        }

        return $this->interpret($definition->key, $response);
    }

    private function probe(string $provider): Response
    {
        $tokenUrl = $this->tokenUrl($provider);
        $values = $this->settings->values($provider);
        $client = PlatformHttp::client($provider);

        if ($provider === 'meta') {
            /*
             * PROVCFG-META-001 — Meta gets the probe Meta documents, not the generic one.
             *
             * The generic probe presents the credentials with a deliberately invalid authorization
             * code and reads the refusal. Meta answers that with «Invalid verification code format.»
             * — and the generic interpreter's positive test is a list of strings (`invalid_grant`,
             * `invalid_code`, `authorization code`, `auth_code`, `invalid_request`) that Meta's
             * wording is on none of. A correct App ID and Secret were therefore reported as unproven,
             * which is what the owner met with a real app configured and the redirect URI accepted.
             *
             * Adding that phrase to the list would be the same defect with one more entry in it. It
             * would need extending for every provider whose copywriter chooses different words, and —
             * decisively — it proves NOTHING: Meta validates the code's SHAPE before it looks the app
             * up, so an app that does not exist is told exactly the same thing. The string is not
             * evidence about credentials in either direction.
             *
             * `grant_type=client_credentials` is Meta's own documented way to obtain an APP access
             * token from an App ID and App Secret. It needs no user, no consent and no authorization
             * code, and it fails with an `OAuthException` when either half of the pair is wrong —
             * which is precisely, and only, the question this stage is entitled to ask.
             *
             * POSTed, though Meta documents the call as a GET with query parameters: a URL carrying a
             * client secret is written into every proxy log, error report and browser history between
             * here and the provider. The Graph endpoint accepts the same parameters in a form body,
             * and a secret in a body is the smaller surface.
             */
            return $client->asForm()->post($tokenUrl, [
                'grant_type' => 'client_credentials',
                'client_id' => $values['client_id'],
                'client_secret' => $values['client_secret'],
            ]);
        }

        if ($provider === 'tiktok') {
            /*
             * TikTok does not use the OAuth parameter names anywhere, including here — and its
             * documented request body is exactly these three fields (TIKTOK-AUTH-001). The
             * `grant_type` that used to ride along was OAuth vocabulary TikTok never asked for; a
             * probe that sends a field the provider does not publish is testing our guess as much as
             * our credentials.
             */
            return $client->post($tokenUrl, [
                'app_id' => $values['client_id'],
                'secret' => $values['client_secret'],
                'auth_code' => self::IMPOSSIBLE_CODE,
            ]);
        }

        $grant = [
            'grant_type' => 'authorization_code',
            'code' => self::IMPOSSIBLE_CODE,
            'redirect_uri' => ProviderCatalogue::get($provider)->redirectUri(),
        ];

        // X authenticates the token call itself rather than taking the pair in the body — which is
        // precisely why it gives the cleanest answer to this question of any of them.
        if ($provider === 'x') {
            return $client->asForm()
                ->withBasicAuth((string) $values['client_id'], (string) $values['client_secret'])
                ->post($tokenUrl, $grant);
        }

        return $client->asForm()->post($tokenUrl, [
            ...$grant,
            'client_id' => $values['client_id'],
            'client_secret' => $values['client_secret'],
        ]);
    }

    /**
     * @return array{passed: bool, message: string}
     */
    private function interpret(string $provider, Response $response): array
    {
        if ($provider === 'meta') {
            return $this->interpretMeta($response);
        }

        $body = strtolower($response->body());
        $reason = $this->scrub($provider, PlatformHttp::reason($response));

        // A 200 here would mean our impossible code was accepted, which no correct server does. It is
        // not a pass; it is a sign we are talking to something other than the provider.
        if ($response->successful() && PlatformHttp::succeeded($response)) {
            return [
                'passed' => false,
                'message' => 'The endpoint accepted an invalid authorisation code, so it is not behaving as the provider\'s token endpoint.',
            ];
        }

        if ($response->status() === 401 || str_contains($body, 'invalid_client') || str_contains($body, 'unauthorized_client')) {
            return [
                'passed' => false,
                'message' => 'The provider does not recognise this client id and secret: '.$reason,
            ];
        }

        // The positive identification. Only a refusal that names the GRANT proves the app was read and
        // accepted; anything else is ambiguous and falls through to the conservative branch below.
        $namesTheGrant = str_contains($body, 'invalid_grant')
            || str_contains($body, 'invalid_code')
            || str_contains($body, 'authorization code')
            || str_contains($body, 'auth_code')
            || str_contains($body, 'invalid_request');

        if ($namesTheGrant) {
            return [
                'passed' => true,
                'message' => 'The provider recognised this app and refused the deliberately invalid code, as it should. '
                    .'This proves the client id and secret only — not scope approval, and not that any account has granted access.',
            ];
        }

        return [
            'passed' => false,
            'message' => 'The provider\'s answer did not identify whether the app was recognised, so this is not recorded as a pass: '.$reason,
        ];
    }

    /**
     * PROVCFG-META-001 — what an app access token does and does not prove.
     *
     * A token means the App ID and App Secret are a real pair that Meta recognises. That is the whole
     * claim. It says nothing about `ads_read`, `ads_management` or `business_management` approval,
     * nothing about App Review, and nothing about any ad account — those are proven only when a real
     * person authorises and an account listing returns, which is a separate stage the product keeps
     * separate.
     *
     * The token itself never leaves this method. Meta's app access token is literally
     * «APP-ID|APP-SECRET» in its simplest form, so echoing the response into `last_test_message`
     * would write the secret into the one column on this table that is NOT encrypted and IS rendered
     * in a browser. Nothing from the body reaches the message on the success path — not a prefix, not
     * a length, not a redacted form of it. There is nothing about the token a reader needs.
     *
     * @return array{passed: bool, message: string}
     */
    private function interpretMeta(Response $response): array
    {
        $token = $response->successful() ? $response->json('access_token') : null;

        if (is_string($token) && $token !== '') {
            return [
                'passed' => true,
                'message' => 'Meta issued an app access token for these credentials, so the App ID and App Secret are a '
                    .'real pair that Meta recognises. That is all this proves: not that ads_read, ads_management or '
                    .'business_management have been approved, not that App Review has passed, and not that any ad '
                    .'account has granted access. Those are proven when someone authorises and their accounts list.',
            ];
        }

        $reason = $this->scrub('meta', PlatformHttp::reason($response));

        /*
         * Meta names both halves of the pair, and both are the same verdict for the operator: the
         * configuration is wrong. Matched on the documented codes rather than on the prose, because
         * prose is what put this row here — code 1 «Error validating client secret», code 101 «Error
         * validating application».
         */
        $code = $response->json('error.code');

        if (in_array($code, [1, 101, 190], true) || $response->status() === 401) {
            return [
                'passed' => false,
                'message' => 'Meta does not recognise this App ID and App Secret as a pair: '.$reason,
            ];
        }

        // Anything else is unread, and unread is not a pass — the direction of doubt is fixed here too.
        return [
            'passed' => false,
            'message' => 'Meta did not answer with an app access token and did not name a credential error, so this is '
                .'not recorded as a pass: '.$reason,
        ];
    }

    private function tokenUrl(string $provider): string
    {
        $file = ProviderCatalogue::get($provider)->kind === ProviderKind::Commerce
            ? 'commerce_platforms'
            : 'ad_platforms';

        return (string) config("{$file}.platforms.{$provider}.token_url");
    }

    /**
     * Remove any configured value from text that is about to be stored and displayed.
     *
     * Providers do echo submitted parameters back in error bodies. Storing one verbatim would put a
     * client secret in `last_test_message`, which is the one column on this table that is NOT
     * encrypted and IS rendered in a browser.
     */
    private function scrub(string $provider, string $text): string
    {
        foreach ($this->settings->values($provider) as $value) {
            if (is_string($value) && mb_strlen($value) >= 6) {
                $text = str_replace($value, '[redacted]', $text);
            }
        }

        return mb_substr(trim($text), 0, 400);
    }

    /**
     * A code no provider can have issued: too long for any of their formats, and namespaced so that a
     * human reading their logs can see what it was and why.
     */
    private const IMPOSSIBLE_CODE = 'campaignshub-configuration-probe-invalid-code-0000000000';
}
