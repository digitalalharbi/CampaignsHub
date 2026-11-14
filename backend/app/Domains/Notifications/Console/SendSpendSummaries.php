<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Console;

use App\Domains\Notifications\Models\ExternalDigestRecipient;
use App\Domains\Notifications\Services\SpendSummaryDispatcher;
use App\Domains\Notifications\Support\DigestSchedule;
use App\Domains\Notifications\Support\MailAddress;
use App\Domains\Ops\Services\ScheduledRunRows;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SPEND-SUMMARY-EXTERNAL-RECIPIENTS-001 — the morning sweep for «ملخص الإنفاق».
 *
 * Hourly, and it acts from 08:00 in each reader's own timezone (Riyadh by default): the workspace reader who switched the weekly
 * on gets it on their chosen weekday; each recipient outside the platform gets it on its cadence (daily; weekly on Sunday;
 * monthly on the 1st). Every send is claimed in its ledger, so a later hour the same day sends nothing twice.
 */
final class SendSpendSummaries extends Command
{
    protected $signature = 'notifications:send-spend-summaries {--force : send every switched-on summary now, whatever the hour or day}';

    protected $description = 'Send «ملخص الإنفاق» to workspace readers and to recipients outside the platform, once per period.';

    public function handle(SpendSummaryDispatcher $dispatcher): int
    {
        $now = Carbon::now();
        $sent = 0;
        $force = (bool) $this->option('force');

        // The workspace readers who switched the weekly «ملخص الإنفاق» on.
        foreach (DB::table('notification_preferences')->whereNull('client_workspace_id')->get() as $row) {
            $digests = $row->digests === null ? [] : (array) json_decode((string) $row->digests, true);
            if (($digests[SpendSummaryDispatcher::SWITCH] ?? false) !== true) {
                continue;
            }
            $local = $now->copy()->setTimezone(DigestSchedule::timezone($row->timezone ?? 'Asia/Riyadh'));
            if (! $force && ($local->hour < (int) ($row->digest_hour ?? 8) || $local->dayOfWeekIso !== DigestSchedule::weekday($row->digest_weekday ?? null))) {
                continue;
            }
            $user = User::query()->find($row->user_id);
            if ($user === null || $user->email === null) {
                continue;
            }
            $state = $dispatcher->sendInternal($user, (string) $row->tenant_id, $local, in_array($row->locale, ['ar', 'en'], true) ? (string) $row->locale : 'ar');
            $state === 'sent' && $sent++;
            $this->line(MailAddress::mask((string) $user->email)." spend: {$state}");
        }

        // The recipients outside the platform, each on its own cadence, at 08:00 Riyadh.
        $local = $now->copy()->setTimezone('Asia/Riyadh');
        if ($force || $local->hour >= 8) {
            ExternalDigestRecipient::query()->withoutGlobalScopes()->where('active', true)->orderBy('id')
                ->each(function (ExternalDigestRecipient $r) use ($dispatcher, $local, $force, &$sent): void {
                    if (! $force && ! SpendSummaryDispatcher::due($r->cadence, $local)) {
                        return;
                    }
                    $state = $dispatcher->sendExternal($r, $local);
                    $state === 'sent' && $sent++;
                    $this->line("external {$r->id} {$r->cadence}: {$state}");
                });
        }

        app(ScheduledRunRows::class)->report($sent);
        $this->info("spend summaries sent={$sent}");

        return self::SUCCESS;
    }
}
