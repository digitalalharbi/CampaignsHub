<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Routing;

use App\Domains\Notifications\Engine\Contracts\QuietHoursPolicy;
use App\Domains\Notifications\Engine\Data\Recipient;
use App\Domains\Notifications\Engine\Enums\Channel;
use App\Domains\Notifications\Engine\Enums\Priority;
use App\Domains\Notifications\Engine\Enums\RecipientKind;
use App\Domains\Notifications\Engine\Enums\Scope;
use App\Domains\Notifications\Engine\Workspace\WorkspaceSettingsRepository;
use App\Domains\Notifications\Services\AlertQuietHours;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Quiet hours, work days and holidays (spec §3), in two layers:
 *
 * 1. Workspace (`workspace_notification_settings`): work days, holidays, quiet window, in the workspace timezone.
 * 2. Personal, internal members only: push follows builder L's `AlertQuietHours` (default 22:00–08:00 on the person's
 *    clock); email follows the explicit window in `notification_preferences` (as `NotificationChoices` always did).
 *
 * Internal `critical` bypasses both layers. External has NO bypass. The in-app centre is never deferred: it makes no
 * sound (the bell's ping obeys quiet hours on its own), and hiding a row until morning would hide information.
 */
final class WorkspaceQuietHours implements QuietHoursPolicy
{
    public function __construct(
        private readonly WorkspaceSettingsRepository $settings,
        private readonly AlertQuietHours $personal,
    ) {}

    public function deferUntil(string $tenantId, Recipient $recipient, Scope $scope, Priority $priority, Channel $channel, Carbon $at): ?Carbon
    {
        if ($channel === Channel::InApp) {
            return null;
        }
        if ($scope === Scope::Internal && $priority === Priority::Critical) {
            return null;
        }

        $ws = $this->settings->for($tenantId);
        $t = $at->copy();

        // Each step can only move forward; a fixed bound keeps a misconfigured week (no work days) from looping.
        for ($i = 0; $i < 30; $i++) {
            $local = $t->copy()->setTimezone($ws->timezone);

            if (in_array($local->toDateString(), $ws->holidays, true) || ! in_array($local->dayOfWeek, $ws->workDays, true)) {
                $t = $this->dayStart($local->copy()->addDay()->startOfDay(), $ws->quietHours)->utc();

                continue;
            }
            if ($ws->quietHours['enabled'] && self::inWindow($local->format('H:i'), $ws->quietHours['start'], $ws->quietHours['end'])) {
                $t = self::windowEnd($local, $ws->quietHours['end'])->utc();

                continue;
            }
            if ($recipient->kind === RecipientKind::User && $recipient->userId !== null && ($moved = $this->personalEnd($tenantId, $recipient->userId, $channel, $t)) !== null) {
                $t = $moved;

                continue;
            }

            return $t->equalTo($at) ? null : $t;
        }

        return $t;
    }

    private function personalEnd(string $tenantId, int $userId, Channel $channel, Carbon $t): ?Carbon
    {
        if ($channel === Channel::Push) {
            return $this->personal->isQuiet($userId, $tenantId, $t) ? $this->personal->endsAt($userId, $tenantId, $t) : null;
        }

        $row = DB::table('notification_preferences')->where('tenant_id', $tenantId)->where('user_id', $userId)->whereNull('client_workspace_id')->first();
        $window = $row?->quiet_hours === null ? null : json_decode((string) $row->quiet_hours, true);
        if (! is_array($window) || ($window['enabled'] ?? false) !== true) {
            return null;
        }
        $tz = in_array((string) ($row->timezone ?? ''), timezone_identifiers_list(), true) ? (string) $row->timezone : 'Asia/Riyadh';
        $local = $t->copy()->setTimezone($tz);

        return self::inWindow($local->format('H:i'), (string) ($window['start'] ?? '22:00'), (string) ($window['end'] ?? '08:00'))
            ? self::windowEnd($local, (string) ($window['end'] ?? '08:00'))->utc()
            : null;
    }

    /** @param array{enabled: bool, start: string, end: string} $quiet */
    private function dayStart(Carbon $midnight, array $quiet): Carbon
    {
        if (! $quiet['enabled']) {
            return $midnight;
        }
        [$h, $m] = array_map('intval', explode(':', $quiet['end']));

        return $midnight->copy()->setTime($h, $m);
    }

    public static function inWindow(string $now, string $start, string $end): bool
    {
        return $start <= $end ? ($now >= $start && $now < $end) : ($now >= $start || $now < $end);
    }

    private static function windowEnd(Carbon $local, string $end): Carbon
    {
        [$h, $m] = array_map('intval', explode(':', $end));
        $at = $local->copy()->setTime($h, $m);

        return $at->lessThanOrEqualTo($local) ? $at->addDay() : $at;
    }
}
