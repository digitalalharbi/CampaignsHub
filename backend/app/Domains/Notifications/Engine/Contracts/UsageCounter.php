<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Contracts;

use Illuminate\Support\Carbon;

/**
 * Per-workspace monthly usage (spec §1). Stored in the existing `usage_counters` table (period = YYYY-MM in the
 * workspace timezone). Increments are atomic upserts.
 */
interface UsageCounter
{
    public const EXTERNAL_MESSAGES = 'notif.external_messages';

    public const EXTERNAL_RECIPIENTS = 'notif.external_recipients';

    public const PUSH_NOTIFICATIONS = 'notif.push_notifications';

    public const CHARTS_GENERATED = 'notif.charts_generated';

    public const METRICS = [self::EXTERNAL_MESSAGES, self::EXTERNAL_RECIPIENTS, self::PUSH_NOTIFICATIONS, self::CHARTS_GENERATED];

    public function increment(string $tenantId, string $metric, int $by = 1, ?Carbon $at = null): int;

    public function current(string $tenantId, string $metric, ?Carbon $at = null): int;

    /** @return array<string, int> metric => count for the month of `$at` */
    public function month(string $tenantId, ?Carbon $at = null): array;
}
