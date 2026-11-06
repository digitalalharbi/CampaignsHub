<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use RuntimeException;

/**
 * A refusal the visitor can act on (LOGIN-004).
 *
 * Distinct from a plain `RuntimeException` because these two outcomes — "link it from your account
 * settings first" and "create an account first" — are not errors in the sense of something having
 * gone wrong. They are the correct answer, and the interface needs the `reason` to say which one it
 * is and offer the right next step rather than a generic failure.
 */
final class OAuthOutcome extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        /**
         * LOGIN-004 — the local account a VERIFIED provider address matches, when linking in place may be offered.
         *
         * Never set for an address the provider did not verify, and never rendered or put in a URL: it is how the callback
         * knows which account the person must prove they own before anything is linked.
         */
        public readonly int|string|null $matchedUserId = null,
    ) {
        parent::__construct($message);
    }
}
