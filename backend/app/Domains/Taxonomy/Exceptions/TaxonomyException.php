<?php

declare(strict_types=1);

namespace App\Domains\Taxonomy\Exceptions;

use RuntimeException;

/**
 * Base for taxonomy engine domain failures (option in use, immutable system field, cross-tenant access,
 * custom options not allowed, …). Carries an HTTP status the controller maps onto the API envelope.
 */
class TaxonomyException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
