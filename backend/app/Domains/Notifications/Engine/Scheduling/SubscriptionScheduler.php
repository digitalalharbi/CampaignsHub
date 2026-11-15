<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Scheduling;

use App\Domains\Notifications\Engine\Contracts\Scheduler;
use App\Domains\Notifications\Engine\Data\Subscription;
use App\Domains\Notifications\Engine\Enums\SubscriptionStatus;
use App\Domains\Notifications\Engine\Workspace\WorkspaceSettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Periodic digests (spec §10): «أرسل [الملخص] عن [الفترة] كل [التكرار] الساعة [الوقت]».
 *
 * - daily: on the chosen weekdays (none chosen = the workspace's work days, so a weekend is not a send day);
 * - weekly: on the first chosen weekday (none = the workspace's first work day);
 * - monthly: on `month_day` (1 by default; clamped to the month's last day).
 *
 * Time and timezone are the subscription's (timezone falls back to the workspace's). Periods come from `DigestPeriods`.
 */
final class SubscriptionScheduler implements Scheduler
{
    public function __construct(private readonly WorkspaceSettingsRepository $settings) {}

    public function due(Carbon $now): array
    {
        $rows = DB::table('notification_subscriptions as s')
            ->join('notification_external_recipients as r', 'r.id', '=', 's.recipient_id')
            ->where('s.status', 'active')->where('r.status', 'active')->whereNotNull('s.frequency')->whereNotNull('s.period_key')
            ->where(fn ($q) => $q->whereNull('s.next_run_at')->orWhere('s.next_run_at', '<=', $now))
            ->orderBy('s.next_run_at')->limit(500)->get(['s.*']);

        $due = [];
        foreach ($rows as $row) {
            $subscription = $this->hydrate($row);
            if ($row->next_run_at === null) {
                // First sight: schedule it, do not fire it now — nothing was promised for this minute.
                DB::table('notification_subscriptions')->where('id', $row->id)->update(['next_run_at' => $this->nextRun($subscription, $now), 'updated_at' => $now]);

                continue;
            }
            $due[] = $subscription;
        }

        return $due;
    }

    public function nextRun(Subscription $subscription, Carbon $after): ?Carbon
    {
        $tz = $subscription->timezone;
        [$h, $m] = array_map('intval', explode(':', $subscription->sendTime.':00'));
        $workDays = $this->settings->for($subscription->tenantId)->workDays;
        $weekdays = $subscription->weekdays !== [] ? $subscription->weekdays : $workDays;
        $day = $after->copy()->setTimezone($tz)->startOfDay();

        for ($i = 0; $i < 400; $i++, $day->addDay()) {
            $candidate = $day->copy()->setTime($h, $m);
            if ($candidate->lte($after)) {
                continue;
            }
            $matches = match ($subscription->frequency) {
                'daily' => in_array($day->dayOfWeek, $weekdays, true),
                'weekly' => $day->dayOfWeek === ($weekdays !== [] ? min($weekdays) : 0),
                'monthly' => $day->day === min(max(1, $subscription->monthDay ?? 1), $day->daysInMonth),
                default => false,
            };
            if ($matches) {
                return $candidate->utc();
            }
        }

        return null;
    }

    public function period(Subscription $subscription, Carbon $runAt): ?array
    {
        if ($subscription->periodKey === null) {
            return null;
        }
        $local = $runAt->copy()->setTimezone($subscription->timezone)->toDateString();

        return DigestPeriods::resolve($subscription->periodKey, $local, fn (string $today): ?string => $this->budgetStart($subscription, $today));
    }

    public function hydrate(object $row): Subscription
    {
        $json = static fn ($v): array => $v === null ? [] : array_values((array) (is_string($v) ? json_decode($v, true) : $v));
        $projects = DB::table('notification_subscription_projects')->where('subscription_id', $row->id)->where('tenant_id', $row->tenant_id)
            ->orderBy('project_id')->pluck('project_id')->map(static fn ($p): string => (string) $p)->all();

        return new Subscription(
            id: (string) $row->id,
            tenantId: (string) $row->tenant_id,
            recipientId: (string) $row->recipient_id,
            status: SubscriptionStatus::from((string) $row->status),
            projectIds: $projects,
            eventKeys: array_map('strval', $json($row->event_keys)),
            digestTypes: array_map('strval', $json($row->digest_types)),
            periodKey: $row->period_key === null ? null : (string) $row->period_key,
            frequency: $row->frequency === null ? null : (string) $row->frequency,
            weekdays: array_map('intval', $json($row->weekdays)),
            monthDay: $row->month_day === null ? null : (int) $row->month_day,
            sendTime: (string) $row->send_time,
            timezone: (string) ($row->timezone ?? $this->settings->for((string) $row->tenant_id)->timezone),
            reviewMode: $row->review_mode === null ? null : (bool) $row->review_mode,
            reviewsRemaining: (int) $row->reviews_remaining,
        );
    }

    /** The first day of the earliest spend limit in force today on the subscription's projects. */
    private function budgetStart(Subscription $subscription, string $today): ?string
    {
        $start = DB::table('spend_limits')->where('tenant_id', $subscription->tenantId)->whereIn('project_id', $subscription->projectIds)
            ->where('active', true)->where('starts_on', '<=', $today)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))->min('starts_on');

        return $start === null ? null : Carbon::parse((string) $start)->toDateString();
    }
}
