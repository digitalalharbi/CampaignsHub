<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Connectors\ValueObjects;

use Carbon\CarbonImmutable;

/** An inclusive date window [from, to] for a metrics sync. Immutable. */
final readonly class SyncWindow
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    /** Trailing window ending today (inclusive), e.g. lastDays(7) → the last 7 days. */
    public static function lastDays(int $days = 7): self
    {
        $days = max(1, $days);
        $today = CarbonImmutable::now()->startOfDay();

        return new self($today->subDays($days - 1), $today);
    }

    public static function of(string $from, string $to): self
    {
        return new self(
            CarbonImmutable::parse($from)->startOfDay(),
            CarbonImmutable::parse($to)->startOfDay(),
        );
    }

    /** @return array{from:string,to:string} */
    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()];
    }
}
