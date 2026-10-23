<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Identity\Services\GoogleIdTokenVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class GoogleIdTokenVerifierTest extends TestCase
{
    private mixed $privateKey;

    /** @var array<string, mixed> */
    private array $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();

        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $windowsConfig = 'C:\\Program Files\\Git\\mingw64\\etc\\ssl\\openssl.cnf';
        if (PHP_OS_FAMILY === 'Windows' && is_file($windowsConfig)) {
            $options['config'] = $windowsConfig;
        }
        $this->privateKey = openssl_pkey_new($options);
        $details = openssl_pkey_get_details($this->privateKey);
        $this->publicKey = [
            'kid' => 'test-key',
            'kty' => 'RSA',
            'n' => $this->encode($details['rsa']['n']),
            'e' => $this->encode($details['rsa']['e']),
        ];

        Http::fake([
            'https://www.googleapis.com/oauth2/v3/certs' => Http::response([
                'keys' => [$this->publicKey],
            ]),
        ]);
    }

    #[Test]
    public function it_accepts_a_signed_google_token_for_this_client_and_flow(): void
    {
        $claims = app(GoogleIdTokenVerifier::class)->verify($this->token(), 'campaignshub-client', 'flow-nonce');

        $this->assertSame('google-user-1', $claims['sub']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidClaims(): array
    {
        return [
            'issuer' => [['iss' => 'https://attacker.example']],
            'audience' => [['aud' => 'another-client']],
            'expiry' => [['exp' => 1]],
            'nonce' => [['nonce' => 'another-flow']],
            'subject' => [['sub' => '']],
            'authorized party for multiple audiences' => [['aud' => ['campaignshub-client', 'other'], 'azp' => 'other']],
        ];
    }

    #[Test]
    #[DataProvider('invalidClaims')]
    public function it_rejects_invalid_security_claims(array $overrides): void
    {
        $this->expectException(RuntimeException::class);
        app(GoogleIdTokenVerifier::class)->verify($this->token($overrides), 'campaignshub-client', 'flow-nonce');
    }

    #[Test]
    public function it_rejects_a_token_whose_signature_does_not_match_its_claims(): void
    {
        $token = $this->token();
        [$header, $payload, $signature] = explode('.', $token);
        $claims = json_decode($this->decode($payload), true);
        $claims['sub'] = 'forged-user';
        $forged = $header.'.'.$this->encode(json_encode($claims, JSON_THROW_ON_ERROR)).'.'.$signature;

        $this->expectException(RuntimeException::class);
        app(GoogleIdTokenVerifier::class)->verify($forged, 'campaignshub-client', 'flow-nonce');
    }

    #[Test]
    public function an_unknown_cached_key_id_forces_one_jwks_refresh_for_rotation(): void
    {
        Cache::put('identity.google.oidc.jwks', [
            ['kid' => 'retired-key', 'kty' => 'RSA', 'n' => 'AQ', 'e' => 'AQAB'],
        ]);
        Http::fake([
            'https://www.googleapis.com/oauth2/v3/certs' => Http::response(['keys' => [$this->publicKey]]),
        ]);

        $claims = app(GoogleIdTokenVerifier::class)->verify($this->token(), 'campaignshub-client', 'flow-nonce');

        $this->assertSame('google-user-1', $claims['sub']);
        Http::assertSentCount(1);
    }

    /** @param array<string, mixed> $overrides */
    private function token(array $overrides = []): string
    {
        $header = $this->encode(json_encode(['alg' => 'RS256', 'kid' => 'test-key'], JSON_THROW_ON_ERROR));
        $claims = array_replace([
            'iss' => 'https://accounts.google.com',
            'aud' => 'campaignshub-client',
            'sub' => 'google-user-1',
            'nonce' => 'flow-nonce',
            'iat' => time(),
            'exp' => time() + 300,
            'email' => 'person@example.test',
            'email_verified' => true,
        ], $overrides);
        $payload = $this->encode(json_encode($claims, JSON_THROW_ON_ERROR));
        openssl_sign($header.'.'.$payload, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return $header.'.'.$payload.'.'.$this->encode($signature);
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'));
    }
}
