<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * NOTIF-SOUND-001 — is this person inside their quiet hours, for the things that make a noise.
 *
 * Asked by the bell's sound and by the mobile push, and by nothing else. `NotificationChoices::inQuietHours()` answers for
 * email and treats «never configured» as «no quiet hours», which is right for mail. A sound in the middle of the night is
 * different: until somebody chooses otherwise it is quiet from 22:00 to 08:00 on the person's own clock. An explicit choice
 * — a window, or «quiet hours off» — always wins over that default.
 */
final class AlertQuietHours
{
    public const DEFAULT_START = '22:00';

    public const DEFAULT_END = '08:00';

    /**
     * The window that applies to this person.
     *
     * @return array{enabled: bool, start: string, end: string, is_default: bool, timezone: string}
     */
    public function window(int $userId, string $tenantId): array
    {
        $row = DB::table('notification_preferences')
            ->where('tenant_id', $tenantId)->where('user_id', $userId)->whereNull('client_workspace_id')->first();

        $timezone = $this->timezone((string) ($row->timezone ?? ''));
        $stored = $row?->quiet_hours === null ? null : json_decode((string) $row->quiet_hours, true);

        if (! is_array($stored) || ! array_key_exists('enabled', $stored)) {
            return ['enabled' => true, 'start' => self::DEFAULT_START, 'end' => self::DEFAULT_END, 'is_default' => true, 'timezone' => $timezone];
        }

        return [
            'enabled' => $stored['enabled'] === true,
            'start' => (string) ($stored['start'] ?? self::DEFAULT_START),
            'end' => (string) ($stored['end'] ?? self::DEFAULT_END),
            'is_default' => false,
            'timezone' => $timezone,
        ];
    }

    public function isQuiet(int $userId, string $tenantId, ?Carbon $at = null): bool
    {
        $w = $this->window($userId, $tenantId);
        if (! $w['enabled']) {
            return false;
        }

        $now = ($at ?? Carbon::now())->copy()->setTimezone($w['timezone'])->format('H:i');

        // The window may wrap past midnight (22:00 → 08:00), which is the usual case.
        return $w['start'] <= $w['end'] ? ($now >= $w['start'] && $now < $w['end']) : ($now >= $w['start'] || $now < $w['end']);
    }

    /** The moment the quiet window ends, from `$at` — when deferred pushes may go out. */
    public function endsAt(int $userId, string $tenantId, ?Carbon $at = null): Carbon
    {
        $w = $this->window($userId, $tenantId);
        $local = ($at ?? Carbon::now())->copy()->setTimezone($w['timezone']);
        [$h, $m] = array_map('intval', explode(':', $w['end']));
        $end = $local->copy()->setTime($h, $m);

        return ($end->lessThanOrEqualTo($local) ? $end->addDay() : $end)->utc();
    }

    private function timezone(string $stored): string
    {
        if ($stored === '' || ! in_array($stored, timezone_identifiers_list(), true)) {
            return 'Asia/Riyadh';
        }

        try {
            Carbon::now($stored);

            return $stored;
        } catch (Throwable) {
            return 'Asia/Riyadh';
        }
    }
}
