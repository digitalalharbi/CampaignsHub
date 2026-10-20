<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Integrations\OAuth\OAuth1;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

/**
 * OAUTH1-X-001 — the signer is pinned against the worked example Twitter published for this algorithm
 * (the `statuses/update` signature walkthrough): a wrong base string, encoding or key composition
 * cannot reproduce that exact signature.
 */
final class OAuth1SignatureTest extends TestCase
{
    private const URL = 'https://api.twitter.com/1.1/statuses/update.json?include_entities=true';

    private function params(): array
    {
        return ['include_entities' => 'true', 'status' => 'Hello Ladies + Gentlemen, a signed OAuth request!'];
    }

    public function test_it_reproduces_the_published_worked_example(): void
    {
        $header = OAuth1::header(
            'POST',
            self::URL,
            $this->params(),
            'xvz1evFS4wEEPTGEFPHBog',
            'kAcSOqF21Fu85e7zjz7ZN2U4ZRhfV3WpwPAoE3Z7kBw',
            '370773112-GmHxMAgYyLbNEtIKZeRNFsMKPR9EyMZeS9weJAEb',
            'LswwdoUaIvS8ltyTt5jkRh4J50vUPVVHtR2YPi5kE',
            [],
            'kYjzVBB8Y0ZFabxSWbWovY3uYSQ2pTgmZeNu2VS4cg',
            1318622958,
        );

        $this->assertStringContainsString('oauth_signature="hCtSmYh%2BiHYCEqBWrE7C7hYmtUk%3D"', $header);
        $this->assertStringStartsWith('OAuth oauth_consumer_key="xvz1evFS4wEEPTGEFPHBog"', $header);
    }

    public function test_the_encoding_is_rfc3986(): void
    {
        $this->assertSame('Ladies%20%2B%20Gentlemen', OAuth1::encode('Ladies + Gentlemen'));
        $this->assertSame('a~b-c_d.e', OAuth1::encode('a~b-c_d.e'));
        $this->assertSame('%E2%9C%93', OAuth1::encode('✓'));
    }

    public function test_the_base_url_drops_query_default_ports_and_case(): void
    {
        $this->assertSame('https://ads-api.x.com/12/accounts', OAuth1::baseUrl('HTTPS://Ads-API.x.com:443/12/accounts?count=1'));
        $this->assertSame('http://example.com:8080/a', OAuth1::baseUrl('http://example.com:8080/a'));
    }

    public function test_the_middleware_signs_with_the_query_string_and_a_form_body(): void
    {
        $sign = OAuth1::signer('ck', 'cs', 'tk', 'ts');
        $get = $sign(new Request('GET', 'https://ads-api.x.com/12/accounts?count=200'));
        $post = $sign(new Request('POST', 'https://api.x.com/oauth/access_token', ['Content-Type' => 'application/x-www-form-urlencoded'], 'oauth_verifier=v1'));

        foreach ([$get, $post] as $signed) {
            $this->assertMatchesRegularExpression('/^OAuth /', $signed->getHeaderLine('Authorization'));
            $this->assertStringContainsString('oauth_token="tk"', $signed->getHeaderLine('Authorization'));
            $this->assertStringContainsString('oauth_signature_method="HMAC-SHA1"', $signed->getHeaderLine('Authorization'));
        }

        // The signature depends on the query: the same call with another count signs differently.
        $other = $sign(new Request('GET', 'https://ads-api.x.com/12/accounts?count=100'));
        $sig = static fn ($r) => preg_match('/oauth_signature="([^"]+)"/', $r->getHeaderLine('Authorization'), $m) === 1 ? $m[1] : '';
        $this->assertNotSame($sig($get), $sig($other));
    }
}
