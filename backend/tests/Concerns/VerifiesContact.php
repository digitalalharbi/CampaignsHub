<?php

declare(strict_types=1);

namespace Tests\Concerns;

/**
 * Test helper: the public intake now requires a verified phone (E.164) + email. This runs the real OTP
 * challenge/response and augments an intake payload with the two verification ids (plus a valid phone +
 * company_name), so existing intake tests exercise the new reality instead of bypassing verification.
 */
trait VerifiesContact
{
    /**
     * @param  array<string,mixed>  $payload  base intake payload (must include contact_email)
     * @return array<string,mixed>
     */
    protected function withVerifiedContact(array $payload): array
    {
        $email = strtolower((string) $payload['contact_email']);
        $phone = $payload['contact_phone'] ?? '+96650'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);

        return array_merge(
            ['company_name' => 'Test Co'],
            $payload,
            [
                'contact_phone' => $phone,
                'phone_verification_id' => $this->runOtp('sms', $phone),
                'email_verification_id' => $this->runOtp('email', $email),
            ],
        );
    }

    /** Start + verify an OTP challenge; return the verified verification id. */
    protected function runOtp(string $channel, string $destination, string $purpose = 'contact_verify'): string
    {
        $start = $this->postJson('/api/v1/requests/verify/start', [
            'channel' => $channel, 'destination' => $destination, 'purpose' => $purpose,
        ]);
        $id = $start->json('data.verification_id');
        $code = $start->json('data.dev_code');
        $this->postJson('/api/v1/requests/verify/check', ['verification_id' => $id, 'code' => $code]);

        return (string) $id;
    }
}
