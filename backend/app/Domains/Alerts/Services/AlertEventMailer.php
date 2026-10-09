<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Services;

use App\Domains\Notifications\Mail\AlertBundleMail;
use App\Domains\Notifications\Providers\ProviderRegistry;
use App\Domains\Notifications\Services\DigestScope;
use App\Domains\Notifications\Services\NotificationChoices;
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
 * findings sweep honours them.
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
    ) {}

    /**
     * @param  list<array<string,mixed>>  $items  one per raised / refreshed / recovered event:
     *                                            event_id, notification_type, severity, title, title_ar,
     *                                            detail, detail_ar, project_id, triggered_at
     * @return array<string,int>
     */
    public function sweep(string $tenantId, array $items, ?Carbon $now = null): array
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
                if ($this->choices->rhythm($userId, $tenantId, $type) !== 'immediate') {
                    $counts['held_for_digest']++;

                    continue;
                }
                if ($this->choices->inQuietHours($userId, $tenantId, $now)) {
                    $counts['held_by_quiet_hours']++;

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
            if ($this->claim($tenantId, $user, $item['period_key'], $now)) {
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

        $ar = $locale === 'ar';
        try {
            Mail::to($user->email)->send(new AlertBundleMail(
                items: array_map(static fn (array $i): array => [
                    'severity' => (string) $i['severity'],
                    'title' => (string) (($ar ? ($i['title_ar'] ?? null) : null) ?? $i['title']),
                    'detail' => (string) (($ar ? ($i['detail_ar'] ?? null) : null) ?? $i['detail']),
                    'context' => (string) ($i['project_name'] ?? ''),
                ], $claimed),
                lang: $locale,
                recipientName: (string) $user->name,
            ));
            $counts['sent'] = $this->finishAll($user, $keys, 'sent', null, $now, $now);
        } catch (Throwable $e) {
            $counts['failed'] = $this->finishAll($user, $keys, 'failed', 'exception', $now, null, $e->getMessage());
        }

        return $counts;
    }

    /** One key per event and trigger moment: the same alert re-raised after its cooldown is a new send. */
    private function periodKey(array $item): string
    {
        return substr(hash('sha256', (string) $item['event_id'].'|'.(string) $item['triggered_at']), 0, 24);
    }

    private function claim(string $tenantId, User $user, string $periodKey, Carbon $now): bool
    {
        $existing = DB::table('digest_sends')
            ->where('user_id', $user->getKey())->where('kind', self::KIND)->where('period_key', $periodKey)->first();

        if ($existing !== null) {
            if ($existing->status !== 'failed' || (int) $existing->attempts >= 3) {
                return false;
            }
            DB::table('digest_sends')->where('id', $existing->id)->update(['attempts' => (int) $existing->attempts + 1, 'updated_at' => $now]);

            return true;
        }

        DB::table('digest_sends')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'user_id' => $user->getKey(), 'kind' => self::KIND,
            'period_key' => $periodKey, 'status' => 'claimed', 'attempts' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return true;
    }

    /** @param  list<string>  $keys */
    private function finishAll(User $user, array $keys, string $status, ?string $reason, Carbon $now, ?Carbon $sentAt = null, ?string $error = null): int
    {
        DB::table('digest_sends')
            ->where('user_id', $user->getKey())->where('kind', self::KIND)->whereIn('period_key', $keys)
            ->update(['status' => $status, 'reason' => $reason, 'last_error' => $error, 'sent_at' => $sentAt, 'updated_at' => $now]);

        return count($keys);
    }
}
