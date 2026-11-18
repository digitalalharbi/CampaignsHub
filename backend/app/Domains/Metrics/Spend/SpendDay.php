<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Spend;

/** One account's real spend on one day in one currency — a row of {@see SpendLedger::accountDays()}. */
final readonly class SpendDay
{
    public function __construct(
        public string $projectId,
        public string $accountId,
        public string $provider,
        public string $date,
        public ?string $currency,
        public float $amount,
    ) {}
}
