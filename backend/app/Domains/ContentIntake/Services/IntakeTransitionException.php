<?php

declare(strict_types=1);

namespace App\Domains\ContentIntake\Services;

use RuntimeException;

/** A refused move. `reason` is a stable machine key the API and the UI translate. */
final class IntakeTransitionException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(public readonly string $reason, public readonly int $http = 409, public readonly array $context = [])
    {
        parent::__construct($reason);
    }
}
