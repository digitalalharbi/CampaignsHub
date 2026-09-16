<?php

declare(strict_types=1);

namespace App\Domains\Integrations\OAuth;

use Closure;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;

/**
 * X-OAUTH1-001 — signs a request with OAuth 1.0a (RFC 5849), as the X Ads API requires.
 *
 * ## Whose cryptography this is
 *
 * Not ours. The signature base string, the parameter normalisation and the HMAC-SHA1 are computed by
 * `guzzlehttp/oauth-subscriber` (`Oauth1::getSignature()`), the Guzzle project's own maintained OAuth
 * 1.0 plugin. Hand-rolled signing is where percent-encoding bugs live, and a signature that is wrong
 * by one encoded character is refused with the same «Could not authenticate you» as a wrong secret.
 *
 * ## Why this is a thin wrapper rather than the plugin's own middleware
 *
 * The plugin mints `oauth_nonce` and `oauth_timestamp` internally with `random_bytes()` and `time()`,
 * and offers no way to fix them. A signature that can only be checked by recomputing it with the same
 * code proves nothing, so this class draws both from `Str::random()` and `Carbon::now()` — the two
 * seams Laravel lets a test pin — and passes them to the plugin's signing method. The only thing written
 * here is the header assembly, which is string formatting over values the plugin produced.
 *
 * ## What it signs
 *
 * The request as it will be sent: method, URL, query string and an `application/x-www-form-urlencoded`
 * body. It runs inside the HTTP client's handler stack, after the query has been merged into the URI,
 * and again on every retry, so a retried request never reuses a nonce.
 */
final class OAuth1Signer
{
    private const VERSION = '1.0';

    private const METHOD = Oauth1::SIGNATURE_METHOD_HMAC;

    private readonly Oauth1 $plugin;

    public function __construct(
        private readonly string $consumerKey,
        string $consumerSecret,
        private readonly ?string $token = null,
        ?string $tokenSecret = null,
        private readonly ?string $callback = null,
        private readonly ?string $verifier = null,
    ) {
        $this->plugin = new Oauth1(array_filter([
            'consumer_key' => $consumerKey,
            'consumer_secret' => $consumerSecret,
            'token_secret' => $tokenSecret,
            'signature_method' => self::METHOD,
        ], static fn ($v) => $v !== null));
    }

    /** Guzzle middleware: sign each request on its way out. */
    public function __invoke(callable $handler): Closure
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            return $handler($this->sign($request), $options);
        };
    }

    public function sign(RequestInterface $request): RequestInterface
    {
        return $request->withHeader('Authorization', $this->authorizationHeader($request));
    }

    public function authorizationHeader(RequestInterface $request): string
    {
        $params = array_filter([
            'oauth_consumer_key' => $this->consumerKey,
            'oauth_nonce' => Str::random(32),
            'oauth_signature_method' => self::METHOD,
            'oauth_timestamp' => (string) Carbon::now()->getTimestamp(),
            'oauth_token' => $this->token,
            'oauth_callback' => $this->callback,
            'oauth_verifier' => $this->verifier,
            'oauth_version' => self::VERSION,
        ], static fn ($v) => $v !== null);

        $params['oauth_signature'] = $this->plugin->getSignature($request, $params);

        // The plugin reads a form body to sign it; hand the stream back at its start for sending.
        if ($request->getBody()->isSeekable()) {
            $request->getBody()->rewind();
        }

        uksort($params, 'strcmp');

        return 'OAuth '.implode(', ', array_map(
            static fn (string $key, string $value) => $key.'="'.rawurlencode($value).'"',
            array_keys($params),
            $params,
        ));
    }
}
