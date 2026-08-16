<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Exceptions;

use RuntimeException;

/**
 * ORCH-100 §K — a cap refused, with the numbers the customer needs to act on.
 *
 * Thrown from inside the transaction that holds the quota lock, so the refusal and the count it was
 * based on cannot disagree. It carries the figures rather than a bare message because «you have
 * reached your limit» is not actionable: the operator needs to know what the limit is, what they are
 * using, and therefore how many they may still select.
 */
final class PlanLimitReached extends RuntimeException
{
    public function __construct(
        public readonly string $metric,
        public readonly int $used,
        public readonly ?int $limit,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** @return array<string,mixed> */
    public function meta(): array
    {
        return [
            'limit_reached' => true,
            'metric' => $this->metric,
            'used' => $this->used,
            'limit' => $this->limit,
            'remaining' => $this->limit === null ? null : max(0, $this->limit - $this->used),
        ];
    }
}
