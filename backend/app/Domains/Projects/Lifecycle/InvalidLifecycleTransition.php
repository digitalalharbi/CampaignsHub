<?php

declare(strict_types=1);

namespace App\Domains\Projects\Lifecycle;

use DomainException;

/** A move the allowed-transitions table refuses (or a restore without confirmation). Rendered as 422. */
final class InvalidLifecycleTransition extends DomainException
{
    public static function between(string $from, string $to): self
    {
        return new self("Transition {$from} → {$to} is not allowed.");
    }
}
