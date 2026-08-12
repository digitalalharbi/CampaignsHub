<?php

declare(strict_types=1);

namespace App\Domains\Legal\Services;

use App\Domains\Legal\Models\DataRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * LEGAL-DELETE-001 — the proof that a deletion request came from the address it names.
 *
 * ## Fail closed, and the shape of that
 *
 * `isActionable()` is the single question the operator inbox and every completion path must ask, and
 * it answers **false unless** a destructive request carries `verified_at`. A request that was never
 * verified, whose code expired, or that exhausted its attempts, cannot be completed — the answer is
 * not «probably fine», it is no.
 *
 * ## Why the code is hashed and compared in constant time
 *
 * A table of live codes beside the requests they unlock is a table that deletes accounts for whoever
 * can read it — a backup, a support query, a log line. Only a SHA-256 is stored. The comparison uses
 * `hash_equals` because a timing difference on a six-character code is a small number of requests
 * away from being read.
 *
 * ## Why the answer is the same whether the reference exists or not
 *
 * `verify()` returns the same refusal for an unknown reference, a wrong code and an expired one.
 * Distinguishing them would turn this endpoint into an oracle for which addresses have asked to be
 * deleted, which is exactly the kind of list this whole unit exists to protect.
 */
final class DeletionVerification
{
    /** Six digits: read aloud over the phone, typed on a mobile keypad, and short-lived. */
    private const CODE_LENGTH = 6;

    private const TTL_MINUTES = 60;

    private const MAX_ATTEMPTS = 5;

    /**
     * Mint a code, store its hash on the request, and return the PLAINTEXT for delivery.
     *
     * The plaintext exists for the length of one method call and is never written anywhere. The
     * caller hands it to the mailer and drops it.
     */
    public function issue(DataRequest $request): string
    {
        $code = str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);

        $request->forceFill([
            'verification_hash' => hash('sha256', $code),
            'verification_sent_at' => now(),
            'verification_expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'verification_attempts' => 0,
            'verified_at' => null,
        ])->save();

        return $code;
    }

    /**
     * Consume a code. Returns the request only when the answer is right, live and within attempts.
     *
     * The lookup is by reference AND email together: a reference alone is a short string somebody
     * could guess at, and the pair is what the requester actually holds.
     */
    public function verify(string $reference, string $email, string $code): ?DataRequest
    {
        $request = DataRequest::query()
            ->where('reference', mb_strtoupper(trim($reference)))
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])
            ->first();

        if ($request === null) {
            return null;
        }

        /*
         * Already proven wins over «there is no code any more», and the order is the whole point.
         *
         * Using a code CLEARS the hash, so asking about the hash first turns a duplicate submit — a
         * double-tapped button, a retried request — into a refusal on a request that is already
         * verified. Idempotent is the correct behaviour: the caller asked for a state that is true.
         */
        if ($request->verified_at !== null) {
            return $request;
        }

        if ($request->verification_hash === null) {
            return null;
        }

        if ($request->verification_attempts >= self::MAX_ATTEMPTS) {
            return null;
        }

        if ($request->verification_expires_at !== null && Carbon::parse($request->verification_expires_at)->isPast()) {
            return null;
        }

        // Counted BEFORE the comparison, so a wrong answer costs an attempt even if the process dies.
        $request->increment('verification_attempts');

        if (! hash_equals((string) $request->verification_hash, hash('sha256', trim($code)))) {
            return null;
        }

        $request->forceFill([
            'verified_at' => now(),
            // Retired on use: a code that still opens the request after it has been used is a second
            // key to the same door.
            'verification_hash' => null,
        ])->save();

        return $request->refresh();
    }

    /**
     * **The gate.** May this request be acted on?
     *
     * A destructive request needs `verified_at`. Everything else is an operator reading a message and
     * deciding what to do, which needs no proof of address to be safe.
     */
    public function isActionable(DataRequest $request): bool
    {
        if (! $request->isDestructive()) {
            return true;
        }

        return $request->verified_at !== null;
    }

    /** Opaque, stable, and safe to hand to a platform that wants a confirmation string. */
    public function confirmationCode(DataRequest $request): string
    {
        return Str::lower(substr(hash('sha256', 'deletion:'.$request->reference), 0, 24));
    }
}
