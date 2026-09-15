<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Integrations\Support\PlatformHttp;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\TestCase;

/**
 * PROVIDER-LIVE-VERIFICATION-001 — a refusal without its identifiers cannot be reported to anyone.
 *
 * `PlatformHttp::reason()` is the shared refusal reader for every connector, and it returned the
 * human sentence alone. Every provider sends more than that, and the extra fields are the ones their
 * own support asks for:
 *
 *   - Meta: `code`, `error_subcode`, `fbtrace_id` — and the subcode is usually the only thing
 *     separating «session expired» from «permission revoked», which are different people's problems.
 *   - Snapchat and TikTok: `request_id` / `log_id`, the reference for a ticket.
 *
 * All of them were discarded on the way into `last_error`, so an operator reading «Error validating
 * access token» had the one sentence that cannot be acted on and none of the identifiers that can.
 *
 * Google is not in this list because `GoogleAdsConnector` already assembles its own richer refusal —
 * `authorizationError=USER_PERMISSION_DENIED · customer … · request …` — and this must not
 * double-report it.
 */
final class RefusalCarriesItsIdentifiersTest extends TestCase
{
    private function response(array $body, int $status = 400): Response
    {
        return new Response(new \GuzzleHttp\Psr7\Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body)));
    }

    public function test_a_meta_refusal_carries_its_code_subcode_and_trace(): void
    {
        $reason = PlatformHttp::reason($this->response([
            'error' => [
                'message' => 'Error validating access token: Session has expired',
                'type' => 'OAuthException',
                'code' => 190,
                'error_subcode' => 463,
                'fbtrace_id' => 'AbCdEf123456',
            ],
        ], 401));

        $this->assertStringContainsString('Session has expired', $reason);
        $this->assertStringContainsString('190', $reason);
        $this->assertStringContainsString('463', $reason);
        $this->assertStringContainsString('AbCdEf123456', $reason);
    }

    /** The historical `(#200)` refusal, which is a permission problem and must read as one. */
    public function test_the_ads_read_refusal_keeps_its_code(): void
    {
        $reason = PlatformHttp::reason($this->response([
            'error' => [
                'message' => '(#200) Ad account owner has NOT grant ads_management or ads_read permission.',
                'type' => 'OAuthException',
                'code' => 200,
                'fbtrace_id' => 'Zz9Trace',
            ],
        ], 403));

        $this->assertStringContainsString('ads_read', $reason);
        $this->assertStringContainsString('200', $reason);
        $this->assertStringContainsString('Zz9Trace', $reason);
    }

    /** A request id is the reference a ticket needs, whatever the provider calls it. */
    public function test_a_request_id_survives(): void
    {
        $reason = PlatformHttp::reason($this->response(['message' => 'Invalid ad account', 'request_id' => 'req-abc-123'], 400));

        $this->assertStringContainsString('Invalid ad account', $reason);
        $this->assertStringContainsString('req-abc-123', $reason);
    }

    /** A plain refusal with nothing to add reads exactly as it did — no decoration invented. */
    public function test_a_bare_message_is_unchanged(): void
    {
        $this->assertSame('Invalid ad account', PlatformHttp::reason($this->response(['message' => 'Invalid ad account'], 400)));
    }

    /** And a body with no message at all still falls back to the status and the raw text. */
    public function test_an_unreadable_body_still_says_something(): void
    {
        $reason = PlatformHttp::reason(new Response(new \GuzzleHttp\Psr7\Response(502, [], 'upstream exploded')));

        $this->assertStringContainsString('502', $reason);
        $this->assertStringContainsString('upstream exploded', $reason);
    }
}
