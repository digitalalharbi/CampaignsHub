<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Exceptions;

use App\Domains\Integrations\Catalogue\ProviderCapability;
use RuntimeException;

/** A caller asked for something this provider never declared. Refused, never assumed (§1.8). */
final class CapabilityNotDeclared extends RuntimeException
{
    public static function for(string $provider, ProviderCapability $capability): self
    {
        return new self("Provider '{$provider}' does not declare '{$capability->value}'.");
    }
}
