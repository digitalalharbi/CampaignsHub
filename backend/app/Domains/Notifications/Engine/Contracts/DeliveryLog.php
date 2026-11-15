<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Contracts;

use App\Domains\Notifications\Engine\Data\Delivery;
use App\Domains\Notifications\Engine\Data\SendResult;
use App\Domains\Notifications\Engine\Enums\DeliveryStatus;

/** The immutable delivery log (spec §4): append-only, no update or delete at DB level, no API to change it. */
interface DeliveryLog
{
    /** Appends one row and returns its id. */
    public function record(Delivery $delivery, DeliveryStatus $status, ?string $reason = null, ?SendResult $result = null): string;
}
