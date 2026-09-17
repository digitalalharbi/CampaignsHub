<?php

declare(strict_types=1);

namespace App\Domains\Integrations\OAuth;

use RuntimeException;

/**
 * A provider answered a credential request with a refusal.
 *
 * Still a `RuntimeException` with the same message, so every existing catch behaves as it did. The
 * parsed body rides along for a caller that wants the provider's own error code and trace id.
 */
final class ProviderRequestRefused extends RuntimeException
{
    /** @param array<string,mixed> $body */
    public function __construct(string $message, public readonly int $status, public readonly array $body = [])
    {
        parent::__construct($message);
    }
}
