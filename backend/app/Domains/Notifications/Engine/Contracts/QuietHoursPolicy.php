<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Contracts;

use App\Domains\Notifications\Engine\Data\Recipient;
use App\Domains\Notifications\Engine\Enums\Channel;
use App\Domains\Notifications\Engine\Enums\Priority;
use App\Domains\Notifications\Engine\Enums\Scope;
use Illuminate\Support\Carbon;

/**
 * Quiet hours + holidays + work days (spec §3). Returns when the delivery may go out, or null for «now».
 * Internal critical bypasses; external never bypasses.
 */
interface QuietHoursPolicy
{
    public function deferUntil(string $tenantId, Recipient $recipient, Scope $scope, Priority $priority, Channel $channel, Carbon $at): ?Carbon;
}
