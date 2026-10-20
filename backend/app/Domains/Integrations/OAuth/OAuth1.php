<?php

declare(strict_types=1);

namespace App\Domains\Integrations\OAuth;

use Psr\Http\Message\RequestInterface;

/**
 * OAUTH1-X-001 — OAuth 1.0a (RFC 5849) request signing, HMAC-SHA1.
 *
 * The X Ads API accepts nothing else: its own documentation states that «all HTTP calls to the Ads API
 * must include an Authorization request header (using OAuth 1.0a)». The three-legged flow is
 * `oauth/request_token` → `oauth/authenticate` → `oauth/access_token`, and every later call carries a
 * header signed with the app's consumer secret and the user's token secret. A bearer token from the
 * OAuth 2.0 flow is a different credential for a different API.
 *
 * Pure and deterministic given a nonce and a timestamp, so it is pinned against the worked example
 * Twitter published for exactly this algorithm.
 */
final class OAuth1
{
    /** RFC 3986 percent-encoding — stricter than `urlencode`, which leaves `~` encoded and spaces as `+`. */
    public static function encode(string $value): string
    {
        return str_replace('%7E', '~', rawurlencode($value));
    }

    /**
     * The `Authorization: OAuth …` header for one request.
     *
     * @param  array<string,string>  $params  every query and form-encoded body parameter of the request
     * @param  array<string,string>  $extra  extra oauth_* values (oauth_callback, oauth_verifier…)
     */
    public static function header(
        string $method,
        string $url,
        array $params,
        string $consumerKey,
        string $consumerSecret,
        ?string $token = null,
        ?string $tokenSecret = null,
        array $extra = [],
        ?string $nonce = null,
        ?int $timestamp = null,
    ): string {
        $oauth = [
            'oauth_consumer_key' => $consumerKey,
            'oauth_nonce' => $nonce ?? bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) ($timestamp ?? time()),
            'oauth_version' => '1.0',
        ] + ($token === null ? [] : ['oauth_token' => $token]) + $extra;

        $oauth['oauth_signature'] = self::signature($method, $url, $params + $oauth, $consumerSecret, $tokenSecret ?? '');

        ksort($oauth);

        return 'OAuth '.implode(', ', array_map(
            static fn (string $k, string $v): string => self::encode($k).'="'.self::encode($v).'"',
            array_keys($oauth),
            array_values($oauth),
        ));
    }

    /**
     * The signature over the base string: METHOD & url & the sorted, encoded parameter list.
     *
     * @param  array<string,string>  $allParams  request parameters plus the oauth_* ones (not the signature)
     */
    public static function signature(string $method, string $url, array $allParams, string $consumerSecret, string $tokenSecret): string
    {
        $pairs = [];
        foreach ($allParams as $k => $v) {
            $pairs[] = [self::encode((string) $k), self::encode((string) $v)];
        }
        sort($pairs);

        $normalised = implode('&', array_map(static fn (array $p): string => $p[0].'='.$p[1], $pairs));
        $base = strtoupper($method).'&'.self::encode(self::baseUrl($url)).'&'.self::encode($normalised);
        $key = self::encode($consumerSecret).'&'.self::encode($tokenSecret);

        return base64_encode(hash_hmac('sha1', $base, $key, true));
    }

    /** scheme://host[:port]/path — lower-case scheme and host, default ports dropped, no query. */
    public static function baseUrl(string $url): string
    {
        $p = parse_url($url);
        $scheme = strtolower((string) ($p['scheme'] ?? 'https'));
        $host = strtolower((string) ($p['host'] ?? ''));
        $port = isset($p['port']) && ! (($scheme === 'https' && $p['port'] === 443) || ($scheme === 'http' && $p['port'] === 80)) ? ':'.$p['port'] : '';

        return $scheme.'://'.$host.$port.($p['path'] ?? '');
    }

    /**
     * A Guzzle request middleware that signs every request it sees, reading the query string and a
     * form-encoded body as the signature requires. JSON bodies are not part of the signature (RFC 5849
     * §3.4.1.3.1), so they are left out.
     *
     * @return callable(RequestInterface): RequestInterface
     */
    public static function signer(string $consumerKey, string $consumerSecret, string $token, string $tokenSecret): callable
    {
        return static function (RequestInterface $request) use ($consumerKey, $consumerSecret, $token, $tokenSecret): RequestInterface {
            $params = [];
            parse_str($request->getUri()->getQuery(), $params);

            if (str_contains(strtolower($request->getHeaderLine('Content-Type')), 'application/x-www-form-urlencoded')) {
                $body = (string) $request->getBody();
                $request->getBody()->rewind();
                $form = [];
                parse_str($body, $form);
                $params = $form + $params;
            }

            $flat = [];
            foreach ($params as $k => $v) {
                $flat[(string) $k] = is_array($v) ? implode(',', array_map('strval', $v)) : (string) $v;
            }

            return $request->withHeader('Authorization', self::header(
                $request->getMethod(),
                (string) $request->getUri(),
                $flat,
                $consumerKey,
                $consumerSecret,
                $token,
                $tokenSecret,
            ));
        };
    }
}
