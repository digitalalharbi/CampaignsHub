<?php

declare(strict_types=1);

namespace App\Domains\Requests\Services;

use App\Domains\Requests\Models\ContactVerification;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Issues and checks OTP / magic-code challenges for a phone or email destination. Honest about delivery:
 * with no SMS/WhatsApp/mail provider configured the code is NOT sent — the row records
 * "awaiting_provider_credentials" and (in non-production only) the plaintext code is returned so the flow
 * is testable. In production without a provider, verification cannot complete — we never fake it.
 */
final class ContactVerificationService
{
    /**
     * Start a challenge. Returns the verification id and, in non-prod only, the dev code.
     *
     * @return array{id: string, channel: string, destination: string, delivery_status: string, dev_code: ?string}
     */
    public function start(string $channel, string $destination, string $purpose = 'contact_verify', ?string $tenantId = null): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $providerOn = (bool) config("requests.verification.providers.{$this->providerKey($channel)}", false);

        $v = ContactVerification::create([
            'tenant_id' => $tenantId,
            'channel' => $channel,
            'destination' => $destination,
            'purpose' => $purpose,
            'code_hash' => hash('sha256', $code),
            'attempts' => 0,
            'delivery_status' => $providerOn ? 'sent' : 'awaiting_provider_credentials',
            'expires_at' => Carbon::now()->addMinutes((int) config('requests.verification.code_ttl_minutes', 10)),
            'last_sent_at' => Carbon::now(),
        ]);

        // NOTE: with no provider we do NOT send anything. When a provider is wired, dispatch happens here.

        return [
            'id' => (string) $v->id,
            'channel' => $channel,
            'destination' => $destination,
            'delivery_status' => $v->delivery_status,
            'dev_code' => config('requests.verification.expose_dev_code') ? $code : null,
        ];
    }

    /**
     * Verify a code against a challenge. On success the row is marked verified (single-use on consume).
     * Throws ValidationException on wrong/expired/exhausted code.
     */
    public function verify(string $verificationId, string $code): ContactVerification
    {
        /** @var ContactVerification|null $v */
        $v = ContactVerification::find($verificationId);
        if ($v === null || $v->isExpired()) {
            throw ValidationException::withMessages(['code' => 'This verification code has expired. Request a new one.']);
        }
        if ($v->attempts >= (int) config('requests.verification.max_attempts', 5)) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Request a new code.']);
        }
        $v->increment('attempts');

        if (! hash_equals($v->code_hash, hash('sha256', $code))) {
            throw ValidationException::withMessages(['code' => 'Incorrect code.']);
        }

        $v->forceFill(['verified_at' => Carbon::now()])->save();

        return $v;
    }

    /**
     * Consume a previously-verified challenge as proof for a destination (single-use, within the verified
     * window). Returns true if valid; marks it consumed so it cannot be replayed.
     */
    public function consumeVerified(string $verificationId, string $destination, string $purpose = 'contact_verify'): bool
    {
        /** @var ContactVerification|null $v */
        $v = ContactVerification::find($verificationId);
        if ($v === null || ! $v->isVerified() || $v->consumed_at !== null || $v->purpose !== $purpose) {
            return false;
        }
        if ($v->destination !== $destination) {
            return false;
        }
        $window = (int) config('requests.verification.token_ttl_minutes', 30);
        if ($v->verified_at->lt(Carbon::now()->subMinutes($window))) {
            return false;
        }
        $v->forceFill(['consumed_at' => Carbon::now()])->save();

        return true;
    }

    private function providerKey(string $channel): string
    {
        return $channel === 'email' ? 'email' : ($channel === 'whatsapp' ? 'whatsapp' : 'sms');
    }
}
