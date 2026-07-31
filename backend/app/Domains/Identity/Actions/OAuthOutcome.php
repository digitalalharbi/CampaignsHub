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
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
