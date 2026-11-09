<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Services;

use App\Domains\Notifications\Mail\AlertBundleMail;
use App\Domains\Notifications\Providers\ProviderRegistry;
use App\Domains\Notifications\Services\DigestScope;
use App\Domains\Notifications\Services\MailSendGate;
use App\Domains\Notifications\Services\MailUnsubscribes;
use App\Domains\Notifications\Services\NotificationChoices;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Domains\Notifications\Support\AlertMailItem;
use App\Domains\Notifications\Support\MailFailure;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * EMAIL-ALERT-EVENTS-001 — the email path for the alert engine's own events.
 *
 * Until now an `alert_events` row reached nobody by email: the evaluator wrote an in-app notification
 * and an `awaiting_credentials` delivery row, and the only alert emails came from the digest-findings
 * sweep. This mailer takes what ONE evaluation sweep raised, refreshed or recovered for a tenant and
 * sends each person one bundle — the same {@see AlertBundleMail} the findings sweep uses, so an
 * alert reads the same whichever engine noticed it.
 *
 * Noise suppression, in the order it is applied: the evaluator's own cooldown (a still-open problem is
 * not re-raised inside it); one email per person per sweep, however many events the sweep produced;
 * a ledger row per (person, event, trigger moment) so a re-run of the same sweep sends nothing twice;
 * the person's own choices — switched off, held for the digest, held by quiet hours — exactly as the
 * findings sweep honours them; and a ceiling on how often one alert reaches one person in 24 hours
 * (`mail.alert_repeats_per_day`), since a rule's cooldown may be as short as five minutes. A recovery is
 * never held: being told it is over is not the nuisance.
 *
 * Delivery is honest: without a configured email provider every bundle is recorded
 * `awaiting_credentials`, never «sent».
 */
final class AlertEventMailer
{
    public const KIND = 'alert_event';

    public function __construct(
        private readonly DigestScope $scope,
        private readonly ProviderRegistry $providers,
        private readonly NotificationChoices $choices,
        private readonly MailSendGate $gate,
        private readonly MailUnsubscribes $unsubscribes,
    ) {}

    /**
     * @param  list<array<string,mixed>>  $items  one per raised / refreshed / recovered event:
     *                                            event_id, notification_type, severity, title, title_ar,
     *                                            detail, detail_ar, project_id, triggered_at
     * @return array<string,int>
     */
    public function sweep(string $tenantId, array $items, ?Carbon $now = null, ?int $onlyUserId = null): array
    {
        $now ??= Carbon::now();
        $counts = ['sent' => 0, 'already_sent' => 0, 'awaiting_credentials' => 0, 'failed' => 0,
            'switched_off' => 0, 'held_for_digest' => 0, 'held_by_quiet_hours' => 0, 'out_of_scope' => 0];

        if ($items === []) {
            return $counts;
        }

        $rows = DB::table('notification_preferences')
            ->where('tenant_id', $tenantId)
            ->whereNull('client_workspace_id')
            // NOTIFICATION-ENROLMENT-001 — the sample alert goes through this very path for ONE person; nobody else's row is read.
            ->when($onlyUserId !== null, fn ($q) => $q->where('user_id', $onlyUserId))
            ->get();

        foreach ($rows as $row) {
            $user = User::query()->find($row->user_id);
            if ($user === null || $user->email === null) {
                continue;
            }
            $userId = (int) $user->getKey();
            $locale = (string) ($row->locale ?? 'ar');
            $projectIds = $this->scope->projectIdsFor($user, $tenantId);
            $eligible = [];

            foreach ($items as $item) {
                $projectId = $item['project_id'] ?? null;
                if ($projectId !== null && ! in_array((string) $projectId, $projectIds, true)) {
                    $counts['out_of_scope']++;

                    continue;
                }
                $type = (string) $item['notification_type'];
                if ($this->choices->chose($userId, $tenantId, $type, 'email') === false) {
                    $counts['switched_off']++;

                    continue;
                }
                if (! $this->choices->meetsImportance($userId, $tenantId, $type, (string) $item['severity'])) {
                    $counts['below_importance'] = ($counts['below_importance'] ?? 0) + 1;

                    continue;
                }
                if ($this->choices->rhythm($userId, $tenantId, $type) !== 'immediate') {
                    $counts['held_for_digest']++;

                    continue;
                }
                if ($this->choices->inQuietHours($userId, $tenantId, $now)) {
                    $counts['held_by_quiet_hours']++;

                    continue;
                }
                if ($this->repeatedTooOften($user, $item, $now)) {
                    $this->holdRepeat($tenantId, $user, $item, $now);
                    $counts['repeat_limit'] = ($counts['repeat_limit'] ?? 0) + 1;

                    continue;
                }
                $eligible[] = $item + ['period_key' => $this->periodKey($item)];
            }

            foreach ($this->deliver($user, $tenantId, $eligible, $locale, $now) as $state => $n) {
                $counts[$state] = ($counts[$state] ?? 0) + $n;
            }
        }

        return $counts;
    }

    /** @param  list<array<string,mixed>>  $eligible */
    private function deliver(User $user, string $tenantId, array $eligible, string $locale, Carbon $now): array
    {
        $counts = [];
        $claimed = [];
        foreach ($eligible as $item) {
            if ($this->claim($tenantId, $user, $item['period_key'], $now, (string) $item['event_id'])) {
                $claimed[] = $item;
            } else {
                $counts['already_sent'] = ($counts['already_sent'] ?? 0) + 1;
            }
        }
        if ($claimed === []) {
            return $counts;
        }
        $keys = array_column($claimed, 'period_key');

        if (! $this->providers->isConfigured('email')) {
            $counts['awaiting_credentials'] = $this->finishAll($user, $keys, 'awaiting_credentials', 'no_email_provider', $now);

            return $counts;
        }

        // EMAIL-GAPS — one bundle is one message against the allowance, however many findings it carries.
        $refusal = $this->gate->refusal($tenantId, (string) $user->email, MailUnsubscribes::ALERTS);

        if ($refusal !== null) {
            $counts[$refusal] = $this->finishAll($user, $keys, $refusal, $refusal, $now);

            return $counts;
        }

        $ar = $locale === 'ar';
        try {
            $sent = Mail::to($user->email)->send((new AlertBundleMail(
                // EMAIL-DIGESTS-OPERATIONAL-001 — what happened, the numbers, where, one next step and the page inside the system; never the stored English or a campaign name as the headline.
                items: AlertMailItem::rank(array_map(static fn (array $i): array => AlertMailItem::build($i, $ar), $claimed)),
                lang: $locale,
                recipientName: (string) $user->name,
            ))->offerUnsubscribe($this->unsubscribes->url($tenantId, (string) $user->email, MailUnsubscribes::ALERTS, (int) $user->getKey())));
            $counts['sent'] = $this->finishAll($user, $keys, 'sent', null, $now, $now, messageId: TransactionalMailer::messageId($sent));
        } catch (Throwable $e) {
            $failure = MailFailure::from($e);
            $counts['failed'] = $this->finishAll($user, $keys, 'failed', 'exception', $now, null, $failure['safe'], failureCode: $failure['code']);
        }

        return $counts;
    }

    /** One key per event and trigger moment: the same alert re-raised after its cooldown is a new send. */
    /** The decision a finding asks for, in one line — by the notification type the rule maps to, never a generated recommendation. */
    public static function action(string $type, bool $ar): string
    {
        return match ($type) {
            'budget_risk' => $ar ? 'قرار اليوم: أبقِ الميزانية أو عدّلها أو أوقف ما لا يؤدي.' : 'Decide today: keep the budget, adjust it, or pause what is not delivering.',
            'sync_failed' => $ar ? 'أعد ربط المصدر حتى تكتمل الأرقام.' : 'Reconnect the source so the figures are complete.',
            'token_expiring' => $ar ? 'جدّد الصلاحية قبل انقطاع البيانات.' : 'Renew the access before the data stops.',
            'sample_alert' => $ar ? 'هذا تنبيه تجريبي لتتأكد أنه يصلك: لا يلزمك أي إجراء.' : 'This is a sample alert so you can see it arrives: no action is needed.',
            'report_failed' => $ar ? 'أعد توليد التقرير أو افتحه لترى السبب.' : 'Regenerate the report, or open it to read why.',
            default => $ar ? 'افتح التنبيه واتخذ قرارًا.' : 'Open the alert and decide.',
        };
    }

    private function periodKey(array $item): string
    {
        return substr(hash('sha256', (string) $item['event_id'].'|'.(string) $item['triggered_at']), 0, 24);
    }

    /** @param  array<string,mixed>  $item */
    private function repeatedTooOften(User $user, array $item, Carbon $now): bool
    {
        $limit = (int) config('mail.alert_repeats_per_day', 3);

        if ($limit <= 0 || $item['severity'] === 'info') {
            return false;
        }

        return DB::table('digest_sends')
            ->where('user_id', $user->getKey())->where('kind', self::KIND)
            ->where('alert_event_id', (string) $item['event_id'])
            ->where('status', 'sent')
            ->where('created_at', '>', $now->copy()->subDay())
            ->count() >= $limit;
    }

    /**
     * Held, and said so. Nothing is dropped silently, and because the row names this trigger a later sweep
     * finds it and does not reconsider.
     *
     * @param  array<string,mixed>  $item
     */
    private function holdRepeat(string $tenantId, User $user, array $item, Carbon $now): void
    {
        DB::table('digest_sends')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'user_id' => $user->getKey(), 'kind' => self::KIND,
            'period_key' => $this->periodKey($item), 'alert_event_id' => (string) $item['event_id'],
            'status' => 'repeat_limit', 'reason' => 'repeat_limit', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function claim(string $tenantId, User $user, string $periodKey, Carbon $now, string $eventId = ''): bool
    {
        $existing = DB::table('digest_sends')
            ->where('user_id', $user->getKey())->where('kind', self::KIND)->where('period_key', $periodKey)->first();

        if ($existing !== null) {
            if ($existing->status !== 'failed' || (int) $existing->attempts >= 3) {
                return false;
            }

            // Conditional, so two overlapping sweeps cannot both re-send one failed alert.
            return DB::table('digest_sends')->where('id', $existing->id)->where('status', 'failed')->update(['status' => 'claimed', 'attempts' => (int) $existing->attempts + 1, 'attempted_at' => $now, 'updated_at' => $now]) === 1;
        }

        // `insertOrIgnore`, because a plain insert threw a unique violation at the loser of a race and took the whole sweep with it.
        return DB::table('digest_sends')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'user_id' => $user->getKey(), 'kind' => self::KIND,
            'period_key' => $periodKey, 'alert_event_id' => $eventId === '' ? null : $eventId, 'status' => 'claimed', 'attempts' => 1, 'attempted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]) > 0;
    }

    /** @param  list<string>  $keys */
    private function finishAll(User $user, array $keys, string $status, ?string $reason, Carbon $now, ?Carbon $sentAt = null, ?string $error = null, ?string $messageId = null, ?string $failureCode = null): int
    {
        DB::table('digest_sends')
            ->where('user_id', $user->getKey())->where('kind', self::KIND)->whereIn('period_key', $keys)
            ->update(['status' => $status, 'reason' => $reason, 'last_error' => $error, 'sent_at' => $sentAt, 'accepted_at' => $status === 'sent' ? $sentAt : null, 'provider_message_id' => $messageId, 'failure_code' => $failureCode, 'updated_at' => $now]);

        return count($keys);
    }
}
