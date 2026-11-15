<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Contracts;

use App\Domains\Notifications\Engine\Data\DeliveryIntent;
use App\Domains\Notifications\Engine\Data\RoutingDecision;

/**
 * One intent → the (recipient, channel) decisions (spec §3).
 *
 * Internal: active internal membership → project reach (the existing permission resolver) → event role routing
 * (workspace override, else catalog default for the workspace type) → required capability → personal preferences →
 * plan entitlement → quiet hours / holidays (critical bypasses).
 * External: active subscriptions naming the project and the event → plan entitlement → quiet hours / holidays (no bypass).
 */
interface Router
{
    /** @return list<RoutingDecision> */
    public function route(DeliveryIntent $intent): array;
}
