<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Contracts;

use App\Domains\Notifications\Engine\Enums\SubscriptionLogAction;

/** The immutable subscription log (spec §4): confirmation, internal approval + who, unsubscribe, reduce, no confirmation in 7 days. */
interface SubscriptionLog
{
    /** @param array<string, mixed> $detail */
    public function record(string $tenantId, SubscriptionLogAction $action, ?string $recipientId = null, ?string $subscriptionId = null, array $detail = [], ?int $actorUserId = null): string;
}
