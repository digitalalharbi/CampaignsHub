<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Legal\Models\DataRequest;
use App\Domains\Legal\Services\DeletionVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * LEGAL-DELETE-001 — the public deletion flow, and the proof it will not act on a claim.
 *
 * ## The defect these pin
 *
 * `POST /data-requests` accepted a deletion naming any address, from anybody, and opened it
 * `pending` — ready for an operator to work through. The `verifying` status existed in the model's
 * enum and nothing set it. So «somebody typed this address into a form» and «somebody who can read
 * that inbox asked for this» were the same event, and the second one is the only one that justifies
 * destroying anything.
 *
 * ## Why the refusals are all the same sentence
 *
 * Several tests below assert that an unknown reference, a wrong code and an expired code produce the
 * SAME answer. That is deliberate and it is the point: an endpoint that distinguishes them is a way
 * to ask which addresses have requested deletion, which is a list of precisely the people with the
 * strongest interest in not being on one.
 */
final class DataDeletionFlowTest extends TestCase
{
    use RefreshDatabase;

    private function submit(array $overrides = []): array
    {
        $response = $this->postJson('/api/v1/data-deletion', array_merge([
            'type' => 'delete_account',
            'name' => 'Sara',
            'email' => 'sara@example.test',
        ], $overrides));

        $response->assertOk();

        return $response->json('data');
    }

    /** The page is public: no session, no redirect, no 401. */
    public function test_the_deletion_endpoints_need_no_session(): void
    {
        $this->assertGuest();

        $data = $this->submit();

        $this->assertNotEmpty($data['reference']);
        $this->assertGuest();

        $this->postJson('/api/v1/data-deletion/status', [
            'reference' => $data['reference'],
            'email' => 'sara@example.test',
        ])->assertOk();
    }

    /** **The defect, pinned.** A destructive request opens `verifying`, never `pending`. */
    public function test_a_destructive_request_is_not_actionable_until_the_address_is_proven(): void
    {
        $data = $this->submit();

        $this->assertSame('verifying', $data['status']);
        $this->assertTrue($data['verification_required']);

        $record = DataRequest::firstWhere('reference', $data['reference']);
        $this->assertNull($record->verified_at);
        $this->assertFalse(app(DeletionVerification::class)->isActionable($record));
    }

    /** A correction is not destructive, so it opens ready to read. Verification is not a tax on everything. */
    public function test_a_non_destructive_request_does_not_ask_for_a_code(): void
    {
        $data = $this->submit(['type' => 'correction']);

        $this->assertSame('pending', $data['status']);
        $this->assertFalse($data['verification_required']);
    }

    /** The code moves it into the queue, and only the right code does. */
    public function test_the_right_code_verifies_and_a_wrong_one_does_not(): void
    {
        $data = $this->submit();
        $record = DataRequest::firstWhere('reference', $data['reference']);
        $code = app(DeletionVerification::class)->issue($record);

        $this->postJson('/api/v1/data-deletion/verify', [
            'reference' => $data['reference'],
            'email' => 'sara@example.test',
            'code' => $code === '000000' ? '111111' : '000000',
        ])->assertStatus(422);

        $this->assertNull($record->fresh()->verified_at);

        /*
         * It leaves `verifying` — and lands on `blocked`, which is the honest answer here.
         *
         * Proving the address is not the same as knowing whose workspace it is. This request names
         * no tenant, so `DeletionBlockers` reports `identity_unverified` and the operator has to
         * establish the account before deleting anything. Two different questions, both of which
         * have to be answered, and only one of them is what a code proves.
         */
        $this->postJson('/api/v1/data-deletion/verify', [
            'reference' => $data['reference'],
            'email' => 'sara@example.test',
            'code' => $code,
        ])->assertOk()
            ->assertJsonPath('data.status', 'blocked')
            ->assertJsonPath('data.blockers.0.code', 'identity_unverified');

        $this->assertNotNull($record->fresh()->verified_at);
    }

    /** A used code does not open the request a second time. */
    public function test_a_code_is_retired_once_it_has_been_used(): void
    {
        $data = $this->submit();
        $record = DataRequest::firstWhere('reference', $data['reference']);
        $code = app(DeletionVerification::class)->issue($record);

        $payload = ['reference' => $data['reference'], 'email' => 'sara@example.test', 'code' => $code];

        $this->postJson('/api/v1/data-deletion/verify', $payload)->assertOk();
        $this->assertNull($record->fresh()->verification_hash);

        // Still OK — already verified is idempotent, not a failure. What is gone is the code's power
        // to verify anything else.
        $this->postJson('/api/v1/data-deletion/verify', $payload)->assertOk();
    }

    /** Five wrong answers retire the code, so a six-digit number cannot be walked. */
    public function test_the_attempts_are_bounded(): void
    {
        $data = $this->submit();
        $record = DataRequest::firstWhere('reference', $data['reference']);
        $code = app(DeletionVerification::class)->issue($record);

        $verification = app(DeletionVerification::class);
        foreach (range(1, 5) as $ignored) {
            $this->assertNull($verification->verify($data['reference'], 'sara@example.test', '999999'));
        }

        // Even the RIGHT code is refused now — the attempts, not the answer, closed it.
        $this->assertNull($verification->verify($data['reference'], 'sara@example.test', $code));
    }

    /** An expired code is refused, in the same words as every other refusal. */
    public function test_an_expired_code_is_refused(): void
    {
        $data = $this->submit();
        $record = DataRequest::firstWhere('reference', $data['reference']);
        $code = app(DeletionVerification::class)->issue($record);
        $record->forceFill(['verification_expires_at' => now()->subMinute()])->save();

        $this->postJson('/api/v1/data-deletion/verify', [
            'reference' => $data['reference'],
            'email' => 'sara@example.test',
            'code' => $code,
        ])->assertStatus(422);
    }

    /** The reference alone is not enough — it travels with the address that owns it. */
    public function test_a_reference_belonging_to_another_address_is_refused(): void
    {
        $data = $this->submit();
        $record = DataRequest::firstWhere('reference', $data['reference']);
        $code = app(DeletionVerification::class)->issue($record);

        $this->postJson('/api/v1/data-deletion/verify', [
            'reference' => $data['reference'],
            'email' => 'someone.else@example.test',
            'code' => $code,
        ])->assertStatus(422);

        $this->postJson('/api/v1/data-deletion/status', [
            'reference' => $data['reference'],
            'email' => 'someone.else@example.test',
        ])->assertStatus(404);
    }

    /** Every step is recorded, and the code never is. */
    public function test_the_request_is_audited_without_the_code(): void
    {
        $data = $this->submit();
        $record = DataRequest::firstWhere('reference', $data['reference']);
        $code = app(DeletionVerification::class)->issue($record);

        $this->postJson('/api/v1/data-deletion/verify', [
            'reference' => $data['reference'], 'email' => 'sara@example.test', 'code' => $code,
        ])->assertOk();

        $logs = AuditLog::whereIn('action', ['legal.deletion.requested', 'legal.deletion.verified'])->get();
        $this->assertCount(2, $logs);

        foreach ($logs as $log) {
            $encoded = json_encode($log->after);
            $this->assertStringNotContainsString($code, (string) $encoded, 'the code must never be written down');
            $this->assertStringNotContainsString((string) $record->verification_hash, (string) $encoded);
        }
    }

    /** With no app secret there is nothing to verify against, so the callback refuses. */
    public function test_the_platform_callback_refuses_when_the_provider_is_not_configured(): void
    {
        Config::set('services.meta_ads.app_secret', null);
        Config::set('ad_platforms.meta.client_secret', null);

        $this->postJson('/api/v1/webhooks/data-deletion/meta', ['signed_request' => 'anything'])
            ->assertStatus(503);

        $this->assertSame(0, DataRequest::count(), 'an unconfigured callback must not open a request');
    }

    /** A body whose signature does not match is refused, and writes nothing. */
    public function test_the_platform_callback_refuses_a_bad_signature(): void
    {
        Config::set('services.meta_ads.app_secret', 'test-secret');

        $payload = $this->base64Url(json_encode(['user_id' => '12345']));

        $this->postJson('/api/v1/webhooks/data-deletion/meta', [
            'signed_request' => $this->base64Url('not-the-signature').'.'.$payload,
        ])->assertStatus(401);

        $this->assertSame(0, DataRequest::count());
    }

    /** A genuine signed request opens ONE verified request and answers in the platform's shape. */
    public function test_a_signed_callback_opens_a_verified_request_and_is_idempotent(): void
    {
        Config::set('services.meta_ads.app_secret', 'test-secret');

        $signed = $this->sign(['user_id' => '12345'], 'test-secret');

        $first = $this->postJson('/api/v1/webhooks/data-deletion/meta', ['signed_request' => $signed])->assertOk();

        $first->assertJsonStructure(['url', 'confirmation_code']);
        $this->assertStringContainsString('/data-deletion', $first->json('url'));

        $record = DataRequest::sole();
        $this->assertSame('provider_callback', $record->source);
        $this->assertSame('meta', $record->source_provider);
        // The signature IS the identity proof — there is no address to mail a code to.
        $this->assertNotNull($record->verified_at);

        // A platform that retries must not get a second reference for one deletion.
        $second = $this->postJson('/api/v1/webhooks/data-deletion/meta', ['signed_request' => $signed])->assertOk();

        $this->assertSame(1, DataRequest::count());
        $this->assertSame($first->json('confirmation_code'), $second->json('confirmation_code'));
    }

    private function sign(array $payload, string $secret): string
    {
        $encoded = $this->base64Url((string) json_encode($payload));

        return $this->base64Url(hash_hmac('sha256', $encoded, $secret, true)).'.'.$encoded;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
