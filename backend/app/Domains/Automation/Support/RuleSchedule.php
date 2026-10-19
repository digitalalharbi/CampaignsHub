<?php

declare(strict_types=1);

namespace App\Domains\Automation\Support;

use App\Domains\Automation\Models\AutomationRule;
use Illuminate\Support\Carbon;

/** When a rule is next due, in its own timezone: every N minutes from now, or daily at a local time. */
final class RuleSchedule
{
    public static function next(AutomationRule $rule, ?Carbon $from = null): Carbon
    {
        $schedule = (array) $rule->schedule;
        $tz = (string) (($schedule['timezone'] ?? null) ?: 'Asia/Riyadh');
        $now = ($from ?? Carbon::now())->copy()->setTimezone($tz);
        $frequency = (string) ($schedule['frequency'] ?? 'hourly');

        if ($frequency === 'daily') {
            [$h, $m] = array_map('intval', explode(':', (string) ($schedule['at'] ?? '09:00')) + [1 => 0]);
            $at = $now->copy()->setTime($h, $m);

            return ($at->greaterThan($now) ? $at : $at->addDay())->utc();
        }

        return $now->copy()->addMinutes(RuleCatalog::FREQUENCIES[$frequency] ?? 60)->utc();
    }
}
