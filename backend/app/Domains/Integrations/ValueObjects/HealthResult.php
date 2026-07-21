<?php

declare(strict_types=1);

namespace App\Domains\Integrations\ValueObjects;

/** Outcome of a connector health check. */
final readonly class HealthResult
{
    public function __construct(
        public bool $healthy,
        public string $message,
        public ?string $lastCheckedAt = null,
    ) {}

    public static function ok(string $message = 'Connector is healthy.'): self
    {
        return new self(true, $message);
    }

    public static function down(string $message): self
    {
        return new self(false, $message);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'healthy' => $this->healthy,
            'message' => $this->message,
            'last_checked_at' => $this->lastCheckedAt,
        ];
    }
}
