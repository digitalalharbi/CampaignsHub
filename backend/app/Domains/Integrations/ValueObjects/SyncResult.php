<?php

declare(strict_types=1);

namespace App\Domains\Integrations\ValueObjects;

/** Result of a sync operation (campaigns, insights, creatives, …). */
final readonly class SyncResult
{
    /** @param array<int,array<string,mixed>> $records */
    public function __construct(
        public bool $success,
        public int $count,
        public array $records = [],
        public ?string $message = null,
    ) {}

    /** @param array<int,array<string,mixed>> $records */
    public static function of(array $records, ?string $message = null): self
    {
        return new self(true, count($records), $records, $message);
    }

    public static function failed(string $message): self
    {
        return new self(false, 0, [], $message);
    }
}
