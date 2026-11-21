<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Services;

use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Models\RegistrationSendLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The volume limits, checked BEFORE a signup challenge is sent (SIGNUP-VERIFY-POLICY-001).
 *
 * A place is reserved under a per-channel lock, so concurrent requests cannot all pass a cap on the same stale count. A
 * refused send calls no provider and leaves the applicant's earlier challenge alive, and it is recorded as refused
 * rather than reported as delivered.
 */
final class RegistrationSendGuard
{
    public function __construct(private readonly RegistrationVerificationSettings $settings) {}

    /**
     * Take a place for one message on this channel, or refuse.
     *
     * @return array{allowed: bool, log: RegistrationSendLog}
     */
    public function reserve(RegistrationRequest $request, string $channel): array
    {
        return DB::transaction(function () use ($request, $channel): array {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ['registration-send:'.$channel]);
            }

            $policy = $this->settings->current();
            $since = Carbon::now()->subDay();
            $counted = fn () => RegistrationSendLog::query()
                ->where('channel', $channel)
                ->where('created_at', '>=', $since)
                ->whereNotIn('outcome', [RegistrationSendLog::BLOCKED_GLOBAL, RegistrationSendLog::BLOCKED_APPLICATION]);

            $cap = $policy[$channel === 'mobile' ? 'sms_daily_cap' : 'email_daily_cap'];
            $perApplication = $policy['per_application_daily_cap'];

            $outcome = RegistrationSendLog::RESERVED;

            if ($perApplication !== null && $counted()->where('registration_request_id', $request->getKey())->count() >= (int) $perApplication) {
                $outcome = RegistrationSendLog::BLOCKED_APPLICATION;
            } elseif ($cap !== null) {
                $used = $counted()->count();

                if ($used >= (int) $cap) {
                    $outcome = RegistrationSendLog::BLOCKED_GLOBAL;
                }

                if ((int) $cap > 0 && (($used + 1) * 100 / (int) $cap) >= (int) $policy['budget_alert_percent']) {
                    Log::warning('Signup verification volume is near its cap.', ['channel' => $channel, 'used' => $used, 'cap' => (int) $cap]);
                }
            }

            $log = new RegistrationSendLog;
            $log->forceFill([
                'registration_request_id' => $request->getKey(),
                'channel' => $channel,
                'outcome' => $outcome,
            ])->save();

            return ['allowed' => $outcome === RegistrationSendLog::RESERVED, 'log' => $log];
        });
    }

    /** Record what actually happened to a reserved message. */
    public function settle(RegistrationSendLog $log, string $outcome): void
    {
        $log->forceFill(['outcome' => $outcome])->save();
    }
}
