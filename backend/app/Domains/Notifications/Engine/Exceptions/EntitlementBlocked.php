<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Exceptions;

use App\Domains\Notifications\Engine\Data\EntitlementState;
use RuntimeException;

/** A plan entitlement refused an action. Rendered by the API as 403 `entitlement_blocked` with `upgrade: true`. */
final class EntitlementBlocked extends RuntimeException
{
    public function __construct(public readonly EntitlementState $state)
    {
        parent::__construct("Entitlement {$state->key->value} is {$state->mode->value} for this workspace.");
    }
}
