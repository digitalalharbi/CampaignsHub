<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Legal\Models\ContactMessage;
use App\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * EDGE-CLIENT-IP-001 — the application sees the visitor's address, and only a trusted proxy can tell it one.
 *
 * Production (2026-11-11, read-only): 2573 of 2573 share-access rows in 30 days carried a private address, three
 * distinct values — the Caddy and nginx containers in front of the backend — and so did every stored contact
 * and support row. Every per-address throttle was one bucket for the whole site. On `origin/main` `25a6a1981`
 * the first and the throttle test below fail: the forwarded address is ignored from every peer.
 *
 * The peer is set with `REMOTE_ADDR`; the stored `ip` of a contact message is what `request()->ip()` returned.
 */
final class ClientIpTrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    private function contactFrom(string $peer, ?string $forwardedFor, string $email): TestResponse
    {
        $headers = $forwardedFor === null ? [] : ['X-Forwarded-For' => $forwardedFor];

        return $this->withServerVariables(['REMOTE_ADDR' => $peer])->postJson('/api/v1/contact', [
            'name' => 'Visitor', 'email' => $email,
            'subject' => 'A question', 'message' => 'I would like to know more about the plans.',
        ], $headers);
    }

    private function storedIp(string $email): ?string
    {
        return ContactMessage::query()->where('email', $email)->value('ip');
    }

    public function test_the_forwarded_address_from_the_proxy_in_front_is_the_client_address(): void
    {
        // Caddy → backend (api.campaignshub.io): one hop, a container on the Docker network.
        $this->contactFrom('172.18.0.4', '203.0.113.7', 'one@example.test')->assertOk();
        $this->assertSame('203.0.113.7', $this->storedIp('one@example.test'));

        // Caddy → frontend nginx → backend (campaignshub.io/api/…): two private hops, the client left of both.
        $this->contactFrom('172.18.0.6', '203.0.113.8, 172.18.0.4', 'two@example.test')->assertOk();
        $this->assertSame('203.0.113.8', $this->storedIp('two@example.test'));
    }

    public function test_a_forwarded_address_from_anyone_else_is_ignored(): void
    {
        // A public peer is not a proxy of ours, whatever it claims.
        $this->contactFrom('198.51.100.9', '203.0.113.7', 'direct@example.test')->assertOk();
        $this->assertSame('198.51.100.9', $this->storedIp('direct@example.test'));
    }

    public function test_a_visitor_cannot_choose_their_address_by_writing_the_header_themselves(): void
    {
        // The visitor sent `X-Forwarded-For: 10.9.9.9, 1.2.3.4`; the proxy appended the address it actually saw.
        $this->contactFrom('172.18.0.4', '10.9.9.9, 1.2.3.4, 203.0.113.7', 'spoof@example.test')->assertOk();
        $this->assertSame('203.0.113.7', $this->storedIp('spoof@example.test'));
    }

    public function test_the_trusted_set_can_be_narrowed_and_is_never_everyone(): void
    {
        config(['security.trusted_proxies' => ['172.18.0.0/16']]);
        $this->contactFrom('10.0.0.5', '203.0.113.7', 'narrow@example.test')->assertOk();
        $this->assertSame('10.0.0.5', $this->storedIp('narrow@example.test'), 'outside the narrowed subnet is not a proxy');

        foreach (['*', '0.0.0.0/0', '::/0', 'REMOTE_ADDR', '0.0.0.0/1', 'not-an-ip'] as $i => $everyone) {
            config(['security.trusted_proxies' => [$everyone]]);
            // A different public peer each time: `/contact` allows five a minute per address.
            $peer = '198.51.100.'.(20 + $i);
            $this->contactFrom($peer, '203.0.113.7', "star{$i}@example.test")->assertOk();
            $this->assertSame($peer, $this->storedIp("star{$i}@example.test"), "`{$everyone}` must not make every peer a proxy");
        }

        // The pinned form the deploy notes recommend: the two proxies' own addresses.
        config(['security.trusted_proxies' => ['172.18.0.4/32', '172.18.0.6']]);
        $this->contactFrom('172.18.0.4', '203.0.113.7', 'pinned@example.test')->assertOk();
        $this->assertSame('203.0.113.7', $this->storedIp('pinned@example.test'));
        $this->contactFrom('172.18.0.9', '203.0.113.7', 'neighbour@example.test')->assertOk();
        $this->assertSame('172.18.0.9', $this->storedIp('neighbour@example.test'), 'a neighbour on the same network is not a proxy');
    }

    public function test_a_forwarded_host_is_still_not_trusted(): void
    {
        $request = Request::create('https://campaignshub.io/api/v1/requests', 'POST', server: [
            'REMOTE_ADDR' => '172.18.0.4',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
            'HTTP_X_FORWARDED_HOST' => 'agency.example.test',
            'HTTP_X_FORWARDED_PROTO' => 'http',
        ]);

        app(TrustProxies::class)->handle($request, function (Request $r): Response {
            $this->assertSame('203.0.113.7', $r->ip());
            // The owner of a public request is decided from the Host; a forwarded host must never pick it.
            $this->assertSame('campaignshub.io', $r->getHost());
            $this->assertTrue($r->isSecure(), 'the scheme stays the one the request arrived with');

            return new Response;
        });
    }

    public function test_each_visitor_behind_the_proxy_has_their_own_throttle_bucket(): void
    {
        // `/contact` is `throttle:5,1` per address. Before this change all of these shared the proxy's bucket.
        foreach (range(1, 5) as $i) {
            $this->contactFrom('172.18.0.4', '203.0.113.7', "a{$i}@example.test")->assertOk();
        }
        $this->contactFrom('172.18.0.4', '203.0.113.7', 'a6@example.test')->assertStatus(429);

        // A different visitor through the same proxy is not refused for the first one's traffic.
        $this->contactFrom('172.18.0.4', '203.0.113.99', 'b1@example.test')->assertOk();
    }
}
