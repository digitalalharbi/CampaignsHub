<?php

declare(strict_types=1);

namespace App\Domains\Requests\Services;

use App\Domains\Requests\Models\ContactVerification;
use App\Support\PhoneNumber;
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
     * ## `$cooldownSeconds` and `$invalidatePrevious` are opt-in, and that is deliberate
     *
     * Both are properties a SIGN-IN credential must have (LOGIN-OTP-001), and neither is safe to
     * switch on for every caller from here. Registration and the client portal issue codes on their
     * own schedules and their suites depend on those schedules; turning invalidation on globally
     * would silently kill a code a second flow had already sent and handed to somebody. The callers
     * that need the stricter contract ask for it.
     *
     * @param  int  $cooldownSeconds  refuse a second code to the same destination inside this window
     * @param  bool  $invalidatePrevious  expire every live code for this destination+purpose first
     * @return array{id: string, channel: string, destination: string, delivery_status: string, dev_code: ?string, resend_after: int}
     */
    public function start(
        string $channel,
        string $destination,
        string $purpose = 'contact_verify',
        ?string $tenantId = null,
        int $cooldownSeconds = 0,
        bool $invalidatePrevious = false,
    ): array {
        if ($cooldownSeconds > 0) {
            $this->assertOutOfCooldown($channel, $destination, $purpose, $cooldownSeconds);
        }

        /*
         * One live code at a time.
         *
         * Without this, asking for a second code leaves the first one working for its whole TTL — so
         * «that code was not mine, I asked for another» does not actually retire the first, and a
         * code read off somebody's screen stays a key until it times out on its own.
         */
        if ($invalidatePrevious) {
            ContactVerification::query()
                ->where('channel', $channel)
                ->where('destination', $destination)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', Carbon::now())
                ->update(['expires_at' => Carbon::now()->subSecond()]);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $providerOn = (bool) config("requests.verification.providers.{$this->providerKey($channel)}", false);

        $v = ContactVerification::create([
            'tenant_id' => $tenantId,
            'channel' => $channel,
            'destination' => $destination,
            'purpose' => $purpose,
            'code_hash' => hash('sha256', $code),
            'attempts' => 0,
            // 'sent' is NEVER recorded without a confirmed send. Provider enabled → 'queued' (dispatch is
            // wired where noted); no provider → 'awaiting_provider_credentials'.
            'delivery_status' => $providerOn ? 'queued' : 'awaiting_provider_credentials',
            'expires_at' => Carbon::now()->addMinutes((int) config('requests.verification.code_ttl_minutes', 10)),
            'last_sent_at' => Carbon::now(),
        ]);

        // NOTE: with no provider we do NOT send anything. When a provider is wired, dispatch happens here.

        return [
            'id' => (string) $v->id,
            'channel' => $channel,
            'destination' => $destination,
            'delivery_status' => $v->delivery_status,
            'dev_code' => self::exposeDevSecrets() ? $code : null,
            'resend_after' => $cooldownSeconds,
        ];
    }

    /**
     * Refuse a second code inside the resend window.
     *
     * Enforced on the SERVER because the countdown the visitor sees is a courtesy, not a control: it
     * lives in a browser tab and disappears the moment somebody posts to the endpoint directly. The
     * throttle bounds the volume; this bounds the rate to one destination, which is what stops the
     * sign-in form from being usable as a way to send somebody a message every second.
     */
    private function assertOutOfCooldown(string $channel, string $destination, string $purpose, int $cooldownSeconds): void
    {
        /** @var ContactVerification|null $recent */
        $recent = ContactVerification::query()
            ->where('channel', $channel)
            ->where('destination', $destination)
            ->where('purpose', $purpose)
            ->whereNotNull('last_sent_at')
            ->orderByDesc('last_sent_at')
            ->first();

        if ($recent === null || $recent->last_sent_at === null) {
            return;
        }

        $elapsed = $recent->last_sent_at->diffInSeconds(Carbon::now());

        if ($elapsed >= $cooldownSeconds) {
            return;
        }

        throw ValidationException::withMessages([
            'destination' => [__('auth.resend_cooldown', ['seconds' => max(1, $cooldownSeconds - (int) $elapsed)])],
        ]);
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
        if (! self::sameDestination($v->channel, $v->destination, $destination)) {
            return false;
        }
        $window = (int) config('requests.verification.token_ttl_minutes', 30);
        if ($v->verified_at->lt(Carbon::now()->subMinutes($window))) {
            return false;
        }
        $v->forceFill(['consumed_at' => Carbon::now()])->save();

        return true;
    }

    /**
     * Whether the proof was issued for the destination now being claimed.
     *
     * PHONE-001 — a phone is compared as a NUMBER, not as a string. The customer verifies
     * «0501234567», then tidies it to «+966501234567» before pressing submit, and a raw `!==` calls
     * that a different phone: the request is refused with «verify your phone and email» beside a
     * green tick saying it already is. One phone, written twice, is one phone.
     *
     * Everything else stays an exact match. An email is normalised by its caller (lowercased) and has
     * no second valid spelling, so loosening it would only widen what a single proof can be replayed
     * against — which is the opposite of what this check is for.
     */
    private static function sameDestination(string $channel, string $issuedFor, string $claimed): bool
    {
        if ($channel === 'sms' || $channel === 'whatsapp') {
            return PhoneNumber::same($issuedFor, $claimed);
        }

        return $issuedFor === $claimed;
    }

    private function providerKey(string $channel): string
    {
        return $channel === 'email' ? 'email' : ($channel === 'whatsapp' ? 'whatsapp' : 'sms');
    }

    /**
     * Whether dev-only secrets (OTP dev_code, portal dev_token) may be surfaced. HARD-gated: NEVER in
     * production, regardless of any config/env override — the testability escape hatch cannot leak live.
     */
    public static function exposeDevSecrets(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        return (bool) config('requests.verification.expose_dev_code');
    }
}
