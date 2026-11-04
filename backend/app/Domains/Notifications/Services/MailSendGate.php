<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The last question before a message leaves — EMAIL-GAPS (§17.4.5).
 *
 * Every sender asks here at send time, not when the message was scheduled, so somebody who leaves while
 * a message waits in the queue does not receive it. The answer is a ledger status with a real reason:
 * nothing is dropped silently.
 *
 * A null scope is a message that cannot be left (see `MessageCatalogue::isUnsubscribable`). It is never
 * refused or counted: a workspace that spends its allowance on digests must still get password resets
 * and renewal notices.
 *
 * ## The allowance is one atomic statement
 *
 * `used < limit` is checked and incremented by the same upsert, so two workers cannot both take the last
 * message, and the row is keyed by tenant, so one workspace cannot take another's. The day is UTC.
 */
final class MailSendGate
{
    public const UNSUBSCRIBED = 'unsubscribed';

    public const LIMIT_REACHED = 'limit_reached';

    public function __construct(private readonly MailUnsubscribes $unsubscribes) {}

    /** @return string|null the status to record instead of sending; null means send */
    public function refusal(?string $tenantId, string $recipient, ?string $scope, bool $charge = true): ?string
    {
        if ($tenantId === null || $scope === null) {
            return null;
        }

        if ($this->unsubscribes->hasLeft($tenantId, $recipient, $scope)) {
            return self::UNSUBSCRIBED;
        }

        return $charge && ! $this->takeOne($tenantId) ? self::LIMIT_REACHED : null;
    }

    private function takeOne(string $tenantId): bool
    {
        $limit = (int) config('mail.daily_limit_per_tenant', 1000);

        if ($limit <= 0) {
            return true;
        }

        return DB::affectingStatement(
            'INSERT INTO mail_daily_usage (tenant_id, day, used) VALUES (?, ?, 1)
             ON CONFLICT (tenant_id, day) DO UPDATE SET used = mail_daily_usage.used + 1
             WHERE mail_daily_usage.used < ?',
            [$tenantId, Carbon::now('UTC')->toDateString(), $limit],
        ) > 0;
    }
}
