<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Contracts;

use App\Domains\Notifications\Engine\Data\Delivery;
use App\Domains\Notifications\Engine\Enums\DeliveryStatus;

/**
 * One automated check run by the sender right before a delivery leaves (spec §8, §9: privacy, review, forbidden words,
 * emoji, currency without rate, incomplete data). Return null to pass, or the status + reason to stop with
 * (`held_for_review`, `held_for_privacy`, `skipped`). Checks are registered with the container tag `notifications.presend`.
 */
interface PreSendCheck
{
    /** @return array{0: DeliveryStatus, 1: string}|null */
    public function check(Delivery $delivery): ?array;
}
