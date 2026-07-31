<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Services;

use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Models\RegistrationVerification;
use App\Domains\Requests\Services\ContactVerificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Proving an email address or a mobile number BEFORE any account exists (SIGNUP-002, SIGNUP-005).
 *
 * The existing `EmailVerificationService` verifies a USER, which is exactly what an applicant does
 * not have — so this is a second implementation on purpose, not by oversight. What the two share is
 * the honesty rule: with no mail or SMS provider wired, nothing is recorded as sent. The status is
 * `awaiting_provider_credentials`, and outside production the secret is handed back so the journey
 * can be walked end to end without pretending a message was delivered.
 */
final class RegistrationVerificationService
{
    /** A link the applicant clicks; long enough that guessing is not a strategy. */
    private const EMAIL_TTL_MINUTES = 1440;

    /** A six-digit code the applicant types, so it lives for minutes rather than a day. */
    private const MOBILE_TTL_MINUTES = 15;

    /** Six digits is 10^6 guesses; this is what keeps that from being enough time to try them. */
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly AdvanceRegistration $advance) {}

    /**
     * Issue a fresh challenge, invalidating any earlier one on the same channel.
     *
     * @return array{channel: string, delivery_status: string, dev_link: ?string, dev_code: ?string, expires_at: string}
     */
    public function send(RegistrationRequest $request, string $channel): array
    {
        // One live challenge per channel: leaving the previous one usable would mean a resend widens
        // the window instead of replacing it.
        RegistrationVerification::query()
            ->where('registration_request_id', $request->getKey())
            ->where('channel', $channel)
            ->whereNull('consumed_at')
            ->delete();

        $secret = $channel === 'mobile' ? (string) random_int(100000, 999999) : Str::random(48);
        $ttl = $channel === 'mobile' ? self::MOBILE_TTL_MINUTES : self::EMAIL_TTL_MINUTES;
        $expiresAt = Carbon::now()->addMinutes($ttl);

        $verification = new RegistrationVerification;
        $verification->forceFill([
            'registration_request_id' => $request->getKey(),
            'channel' => $channel,
            'token_hash' => hash('sha256', $secret),
            'delivery_status' => 'awaiting_provider_credentials',
            'expires_at' => $expiresAt,
        ])->save();

        $dev = ContactVerificationService::exposeDevSecrets();

        return [
            'channel' => $channel,
            // Never 'sent'. No provider is wired, and saying otherwise would be the exact claim the
            // contract forbids.
            'delivery_status' => 'awaiting_provider_credentials',
            'dev_link' => $dev && $channel === 'email'
                ? '/signup/status?request='.$request->getKey().'&token='.$secret
                : null,
            'dev_code' => $dev && $channel === 'mobile' ? $secret : null,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Consume an emailed link token.
     *
     * Found by the hash alone — the token IS the proof, and asking for the request id alongside it
     * would only make the link longer without making it harder to forge.
     */
    public function verifyEmail(string $token): RegistrationRequest
    {
        $verification = RegistrationVerification::query()
            ->where('channel', 'email')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($verification === null || $verification->consumed_at !== null) {
            throw ValidationException::withMessages([
                'token' => 'This verification link is invalid or has already been used.',
            ]);
        }
        if ($verification->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'token' => 'This verification link has expired. Request a new one.',
            ]);
        }

        $verification->forceFill(['consumed_at' => Carbon::now()])->save();

        // Record the fact, then ask what follows. Whether a workspace appears is the policy's answer,
        // not this method's.
        return $this->advance->emailVerified($verification->request);
    }

    /** Check a mobile code against the live challenge for this application. */
    public function verifyMobile(RegistrationRequest $request, string $code): RegistrationRequest
    {
        $verification = RegistrationVerification::query()
            ->where('registration_request_id', $request->getKey())
            ->where('channel', 'mobile')
            ->whereNull('consumed_at')
            ->latest('created_at')
            ->first();

        if ($verification === null || $verification->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'code' => 'This code has expired. Request a new one.',
            ]);
        }
        if ($verification->attempts >= self::MAX_ATTEMPTS) {
            throw ValidationException::withMessages([
                'code' => 'Too many incorrect attempts. Request a new code.',
            ]);
        }

        if (! hash_equals($verification->token_hash, hash('sha256', $code))) {
            // Count the miss before answering, so a wrong guess costs the attacker one of five even
            // if they abandon the connection.
            $verification->forceFill(['attempts' => $verification->attempts + 1])->save();

            throw ValidationException::withMessages([
                'code' => 'That code is not correct.',
            ]);
        }

        $verification->forceFill(['consumed_at' => Carbon::now()])->save();

        return $this->advance->mobileVerified($request);
    }
}
