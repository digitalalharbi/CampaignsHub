<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Data;

use App\Domains\Notifications\Engine\Enums\Channel;
use App\Domains\Notifications\Engine\Enums\DeliveryStatus;
use App\Domains\Notifications\Engine\Enums\Priority;
use App\Domains\Notifications\Engine\Enums\Scope;
use Illuminate\Support\Carbon;

/**
 * The router's answer for one (recipient, channel). `refusal` null = deliver (at `notBefore` when deferred);
 * otherwise the status the engine writes to the log (skipped / blocked_by_plan).
 */
final class RoutingDecision
{
    public function __construct(
        public readonly Recipient $recipient,
        public readonly Scope $scope,
        public readonly Channel $channel,
        public readonly Priority $priority,
        public readonly ?DeliveryStatus $refusal = null,
        public readonly ?string $reason = null,
        public readonly ?Carbon $notBefore = null,
        public readonly ?string $deferredReason = null,
        public readonly ?string $subscriptionId = null,
    ) {}

    public function delivers(): bool
    {
        return $this->refusal === null;
    }
}
