<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Support\MetaGraphRefusal;
use App\Domains\Integrations\Support\PlatformHttp;
use App\Domains\Integrations\Support\ProviderRefusal;
use App\Domains\Integrations\Support\ProviderSessionInvalidation;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * META-GRAPH-REFUSAL-001 — a Graph refusal is read by Meta's own code, per the published error tables
 * (Graph API error handling; Marketing API rate limiting), never by the sentence alone.
 */
final class MetaGraphRefusalTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function refusals(): array
    {
        return [
            'password changed' => ['Error validating access token: The session has been invalidated · code 190 · subcode 460 · trace AbC', MetaGraphRefusal::SESSION_INVALID, 'customer'],
            'api session' => ['API session · code 102', MetaGraphRefusal::SESSION_INVALID, 'customer'],
            'ad account grant' => ['(#200) Ad account owner has NOT grant ads_management or ads_read permission · code 200', MetaGraphRefusal::PERMISSION_MISSING, 'customer'],
            'app permission' => ['(#10) Application does not have permission for this action · code 10', MetaGraphRefusal::PERMISSION_MISSING, 'customer'],
            'unreachable object' => ['Unsupported get request. Object with ID act_1 does not exist · code 100 · subcode 33', MetaGraphRefusal::ACCOUNT_UNREACHABLE, 'customer'],
            'user limit' => ['User request limit reached · code 17 · subcode 2446079', MetaGraphRefusal::RATE_LIMITED, 'nobody'],
            'app limit' => ['Application request limit reached · code 4', MetaGraphRefusal::RATE_LIMITED, 'nobody'],
            'ad account limit' => ['Too many calls · code 613 · subcode 1487742', MetaGraphRefusal::RATE_LIMITED, 'nobody'],
            'business use case' => ['There have been too many calls from this ad-account · code 80004', MetaGraphRefusal::RATE_LIMITED, 'nobody'],
            'no code, limit sentence' => ['Application request limit reached', MetaGraphRefusal::RATE_LIMITED, 'nobody'],
            'unknown' => ['An unknown error has occurred · code 1', MetaGraphRefusal::TRANSIENT, 'nobody'],
            'service' => ['Service temporarily unavailable · code 2', MetaGraphRefusal::TRANSIENT, 'nobody'],
            'too much data' => ["Please reduce the amount of data you're asking for, then retry your request · code 1", MetaGraphRefusal::DATA_VOLUME, 'nobody'],
            'budget sharing' => ['Invalid parameter · code 100 · subcode 4834011', MetaGraphRefusal::REQUEST_REJECTED, 'product'],
            'policy block' => ['Temporarily blocked for policy violations · code 368', MetaGraphRefusal::POLICY_BLOCK, 'customer'],
            'timeout' => ['cURL error 28: Operation timed out', MetaGraphRefusal::TRANSIENT, 'nobody'],
            'unseen' => ['Something nobody has seen', MetaGraphRefusal::UNCLASSIFIED, 'product'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_each_refusal_reads_as_its_own_kind(string $message, string $kind, string $actor): void
    {
        $read = MetaGraphRefusal::read($message);

        $this->assertNotNull($read);
        $this->assertSame($kind, $read->kind);
        $this->assertSame($actor, $read->actor());
        $this->assertNotSame('', $read->actionAr());
        $this->assertNotSame('', $read->actionEn());
    }

    public function test_the_identifiers_are_parsed_and_a_subcode_or_trace_is_not_taken_for_a_code(): void
    {
        $read = MetaGraphRefusal::read('Session · code 190 · subcode 460 · trace A401x');

        $this->assertSame(190, $read?->code);
        $this->assertSame(460, $read?->subcode);
        $this->assertSame('A401x', $read?->trace);

        // A subcode of 190 is no session verdict; the code is what decides.
        $this->assertSame(MetaGraphRefusal::RATE_LIMITED, MetaGraphRefusal::read('Limit · code 17 · subcode 190')?->kind);
        $this->assertNull(MetaGraphRefusal::read(null));
        $this->assertNull(MetaGraphRefusal::read('  '));
    }

    public function test_only_a_dead_session_or_a_throttle_stops_the_next_call(): void
    {
        $this->assertTrue(MetaGraphRefusal::read('x · code 190')?->stopsFurtherCalls());
        $this->assertTrue(MetaGraphRefusal::read('x · code 17')?->stopsFurtherCalls());
        $this->assertFalse(MetaGraphRefusal::read('(#10) no · code 10')?->stopsFurtherCalls());
        $this->assertFalse(MetaGraphRefusal::read('x · code 2')?->stopsFurtherCalls());
    }

    public function test_a_checkpointed_person_is_sent_to_facebook_not_to_a_reconnect_loop(): void
    {
        $read = MetaGraphRefusal::read('Checkpointed · code 190 · subcode 459');

        $this->assertStringContainsString('facebook.com', (string) $read?->actionEn());
        $this->assertStringContainsString('facebook.com', (string) $read?->actionAr());
    }

    public function test_code_102_is_the_same_dead_session_as_190(): void
    {
        $this->assertTrue(ProviderSessionInvalidation::invalidatedBy('meta', 'API session · code 102'));
        $this->assertFalse(ProviderSessionInvalidation::invalidatedBy('meta', 'Something · subcode 102'));
        $this->assertFalse(ProviderSessionInvalidation::invalidatedBy('tiktok', 'Something · code 102'));
    }

    /**
     * The defect behind the wrong sentence on an expired Meta link: the stored form of a 190 names neither
     * «permission» nor `(#190)`, so a dead token read as «needs a fresh sync», which the sweep will never run.
     */
    public function test_a_dead_session_in_its_stored_form_is_a_refusal_about_access(): void
    {
        $this->assertTrue(ProviderRefusal::isPermission('Error validating access token: The session has been invalidated · code 190 · subcode 460'));
        $this->assertTrue(ProviderRefusal::isPermission('API session · code 102'));
        $this->assertTrue(ProviderRefusal::isPermission('Unsupported get request · code 100 · subcode 33'));
        $this->assertFalse(ProviderRefusal::isPermission('User request limit reached · code 17 · subcode 2446079'));
        $this->assertFalse(ProviderRefusal::isPermission('Calls to this api have exceeded · code 613'));
        $this->assertFalse(ProviderRefusal::isPermission('Something · subcode 190'));
    }

    /** `Invalid parameter` alone cannot be acted on; Meta says which and why in `error_user_*` and `blame_field_specs`. */
    public function test_a_rejected_parameter_keeps_metas_own_explanation_and_the_blamed_field(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['error' => [
            'message' => 'Invalid parameter', 'type' => 'OAuthException', 'code' => 100, 'error_subcode' => 4834011,
            'error_user_title' => 'Budget sharing', 'error_user_msg' => 'Must specify True or False in is_adset_budget_sharing_enabled field',
            'error_data' => ['blame_field_specs' => [['is_adset_budget_sharing_enabled']]],
            'fbtrace_id' => 'Tr1',
        ]], 400)]);

        $reason = PlatformHttp::reason(Http::get('https://graph.facebook.com/v25.0/act_1/campaigns'));

        $this->assertStringContainsString('Invalid parameter', $reason);
        $this->assertStringContainsString('Must specify True or False in is_adset_budget_sharing_enabled field', $reason);
        $this->assertStringContainsString('field is_adset_budget_sharing_enabled', $reason);
        $this->assertStringContainsString('code 100', $reason);
        $this->assertStringContainsString('subcode 4834011', $reason);
    }
}
