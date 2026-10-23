<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Verifies Google OIDC ID tokens before any claim is trusted. */
final class GoogleIdTokenVerifier
{
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private const CACHE_KEY = 'identity.google.oidc.jwks';

    /** @return array<string, mixed> */
    public function verify(string $token, string $clientId, string $nonce): array
    {
        [$encodedHeader, $encodedPayload, $encodedSignature] = $this->parts($token);
        $header = $this->json($encodedHeader);
        $claims = $this->json($encodedPayload);

        if (($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) {
            throw new RuntimeException('The identity token uses an unsupported signature.');
        }

        $key = $this->key((string) $header['kid']);
        $pem = $this->publicKey($key);
        $verified = openssl_verify(
            $encodedHeader.'.'.$encodedPayload,
            $this->decode($encodedSignature),
            $pem,
            OPENSSL_ALGO_SHA256,
        );

        if ($verified !== 1) {
            throw new RuntimeException('The identity token signature is invalid.');
        }

        $issuer = $claims['iss'] ?? null;
        if (! in_array($issuer, ['https://accounts.google.com', 'accounts.google.com'], true)) {
            throw new RuntimeException('The identity token issuer is invalid.');
        }

        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];
        if (! in_array($clientId, $audiences, true)) {
            throw new RuntimeException('The identity token audience is invalid.');
        }
        if (count($audiences) > 1 && ($claims['azp'] ?? null) !== $clientId) {
            throw new RuntimeException('The identity token authorized party is invalid.');
        }

        $now = time();
        if (! is_int($claims['exp'] ?? null) || $claims['exp'] <= $now) {
            throw new RuntimeException('The identity token has expired.');
        }
        if (isset($claims['iat']) && (! is_int($claims['iat']) || $claims['iat'] > $now + 60)) {
            throw new RuntimeException('The identity token issue time is invalid.');
        }
        if (! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])) {
            throw new RuntimeException('The identity token did not match this sign-in.');
        }
        if (! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new RuntimeException('The provider did not identify the account.');
        }

        return $claims;
    }

    /** @return array{string, string, string} */
    private function parts(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            throw new RuntimeException('The provider returned an unreadable identity token.');
        }

        return [$parts[0], $parts[1], $parts[2]];
    }

    /** @return array<string, mixed> */
    private function json(string $encoded): array
    {
        $decoded = json_decode($this->decode($encoded), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('The provider returned an unreadable identity token.');
        }

        return $decoded;
    }

    private function decode(string $encoded): string
    {
        $padded = strtr($encoded, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            throw new RuntimeException('The provider returned an unreadable identity token.');
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function key(string $kid): array
    {
        $keys = $this->keys(false);
        $match = collect($keys)->firstWhere('kid', $kid);

        // Google rotates keys. An unknown kid gets one uncached retry before the token is refused.
        if (! is_array($match)) {
            $keys = $this->keys(true);
            $match = collect($keys)->firstWhere('kid', $kid);
        }
        if (! is_array($match) || ($match['kty'] ?? null) !== 'RSA') {
            throw new RuntimeException('The identity token signing key is unavailable.');
        }

        return $match;
    }

    /** @param array<string, mixed> $key */
    private function publicKey(array $key): string
    {
        $certificate = $key['x5c'][0] ?? null;
        if (is_string($certificate) && $certificate !== '') {
            return "-----BEGIN CERTIFICATE-----\n".chunk_split($certificate, 64, "\n")."-----END CERTIFICATE-----\n";
        }

        if (! is_string($key['n'] ?? null) || ! is_string($key['e'] ?? null)) {
            throw new RuntimeException('The identity token signing key is unavailable.');
        }

        $rsa = $this->sequence($this->integer($this->decode($key['n'])).$this->integer($this->decode($key['e'])));
        $algorithm = hex2bin('300d06092a864886f70d0101010500'); // rsaEncryption + NULL
        $spki = $this->sequence($algorithm."\x03".$this->length(strlen($rsa) + 1)."\x00".$rsa);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private function integer(string $value): string
    {
        $value = ltrim($value, "\x00");
        if ($value === '' || (ord($value[0]) & 0x80) !== 0) {
            $value = "\x00".$value;
        }

        return "\x02".$this->length(strlen($value)).$value;
    }

    private function sequence(string $value): string
    {
        return "\x30".$this->length(strlen($value)).$value;
    }

    private function length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $encoded = '';
        while ($length > 0) {
            $encoded = chr($length & 0xFF).$encoded;
            $length >>= 8;
        }

        return chr(0x80 | strlen($encoded)).$encoded;
    }

    /** @return list<array<string, mixed>> */
    private function keys(bool $refresh): array
    {
        if (! $refresh) {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $response = Http::acceptJson()->timeout(5)->get(self::JWKS_URL);
        if (! $response->successful() || ! is_array($response->json('keys'))) {
            throw new RuntimeException('Google signing keys could not be loaded.');
        }

        $keys = array_values(array_filter($response->json('keys'), 'is_array'));
        Cache::put(self::CACHE_KEY, $keys, now()->addHour());

        return $keys;
    }
}
