<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Contracts;

use App\Domains\Notifications\Engine\Data\Delivery;
use App\Domains\Notifications\Engine\Data\SendResult;
use App\Domains\Notifications\Engine\Enums\Channel;

/**
 * One channel's transport (spec §4). Returns `accepted` only when the provider accepted the message. Never throws for
 * a provider failure: it returns `failed` (transient or permanent) and the pipeline decides the retry.
 */
interface Sender
{
    public function channel(): Channel;

    public function send(Delivery $delivery): SendResult;
}
