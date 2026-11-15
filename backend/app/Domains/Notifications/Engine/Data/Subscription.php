<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Data;

use App\Domains\Notifications\Engine\Enums\SubscriptionStatus;

/** An external subscription as the scheduler and router read it (spec §9, §10). */
final class Subscription
{
    /**
     * @param  list<string>  $projectIds
     * @param  list<string>  $eventKeys
     * @param  list<string>  $digestTypes
     * @param  list<int>  $weekdays  0 = Sunday
     */
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $recipientId,
        public readonly SubscriptionStatus $status,
        public readonly array $projectIds,
        public readonly array $eventKeys,
        public readonly array $digestTypes,
        public readonly ?string $periodKey,
        public readonly ?string $frequency,
        public readonly array $weekdays,
        public readonly ?int $monthDay,
        public readonly string $sendTime,
        public readonly string $timezone,
        public readonly ?bool $reviewMode,
        public readonly int $reviewsRemaining,
    ) {}
}
