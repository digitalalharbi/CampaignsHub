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
        /**
         * TIKTOK-METRIC-REFUSAL-001 — metric fields the provider refused by name and the sync left out
         * so the rest of the report could land. Non-empty makes a successful sync PARTIAL.
         *
         * @var list<string>
         */
        public array $refusedMetrics = [],
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $records
     * @param  list<string>  $refusedMetrics
     */
    public static function of(array $records, ?string $message = null, array $refusedMetrics = []): self
    {
        return new self(true, count($records), $records, $message, $refusedMetrics);
    }

    public static function failed(string $message): self
    {
        return new self(false, 0, [], $message);
    }
}
