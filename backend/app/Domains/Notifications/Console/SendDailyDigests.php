<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Console;

use App\Domains\Notifications\Services\DigestDispatcher;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The hourly sweep that sends each person their digest at THEIR eight o'clock — MAIL-003.
 *
 * ## Why hourly rather than «daily at 08:00»
 *
 * A single daily run sends at the server's eight o'clock, which is somebody else's three in the
 * morning. A digest that arrives while its reader is asleep is read at lunchtime, by which point it
 * describes a day they have already half-lived. So the command runs every hour and asks, of each
 * recipient, whether it is currently their chosen hour in their own timezone.
 *
 * The stored preference is the LOCAL hour they picked. Moving timezone therefore changes when the
 * mail arrives and not what they asked for, which is the behaviour a person expects and the reason
 * the conversion happens here rather than at write time.
 *
 * ## What «yesterday» means
 *
 * Yesterday in the RECIPIENT's timezone, not the server's. Two people in different countries
 * reading the same account get the same day's figures for their own day, which is the only
 * definition that matches what either of them means by «yesterday».
 *
 * Sending is idempotent by database constraint (see {@see DigestDispatcher}), so a scheduler that
 * fires twice, a manual run, and a retry all converge on one email.
 */
final class SendDailyDigests extends Command
{
    protected $signature = 'notifications:send-digests
        {--user= : Send for one user id only — for verifying a change without mailing an account}
        {--force : Ignore the recipient’s chosen hour (still idempotent per period)}
        {--date= : The day to summarise, YYYY-MM-DD. Defaults to the recipient’s yesterday}';

    protected $description = 'Send each recipient their daily performance digest at their own local hour.';

    public function handle(DigestDispatcher $dispatcher): int
    {
        $now = Carbon::now('UTC');
        $sent = 0;
        $skipped = 0;

        /*
         * Driven from the PREFERENCES table, not from the users table.
         *
         * A digest is opt-in: a row here is somebody who asked. Iterating users and defaulting them
         * in would mail every account in the installation the first time this ran.
         */
        $rows = DB::table('notification_preferences')
            ->whereNull('client_workspace_id')
            ->when($this->option('user') !== null, fn ($q) => $q->where('user_id', (int) $this->option('user')))
            ->get();

        foreach ($rows as $row) {
            if (! $this->wantsDailyDigest($row)) {
                continue;
            }

            $timezone = $this->timezone((string) ($row->timezone ?? 'Asia/Riyadh'));
            $local = $now->copy()->setTimezone($timezone);

            if (! $this->option('force') && $local->hour !== (int) ($row->digest_hour ?? 8)) {
                continue;
            }

            $user = User::query()->find($row->user_id);
            if ($user === null || $user->email === null) {
                continue;
            }

            // «Yesterday» in the reader's own timezone — see the note above.
            $day = $this->option('date') !== null
                ? Carbon::parse((string) $this->option('date'), $timezone)
                : $local->copy()->subDay();

            $state = $dispatcher->sendDaily(
                $user,
                (string) $row->tenant_id,
                $day->startOfDay(),
                (string) ($row->locale ?? 'ar'),
            );

            $state === 'sent' ? $sent++ : $skipped++;
            $this->line("{$user->email}: {$state}");
        }

        $this->info("digests sent={$sent} other={$skipped}");

        return self::SUCCESS;
    }

    /**
     * Whether this row asked for the daily digest.
     *
     * Absent means NO. An opt-in that defaults to on is not an opt-in, and the first scheduled run
     * after a deploy is the worst possible moment to discover that.
     */
    private function wantsDailyDigest(object $row): bool
    {
        $digests = $row->digests === null ? [] : (array) json_decode((string) $row->digests, true);

        return ($digests['daily'] ?? false) === true;
    }

    /**
     * A stored timezone that PHP does not recognise falls back rather than throwing.
     *
     * One bad row would otherwise abort the sweep and silence everybody else's digest — a single
     * malformed preference taking down the feature for the whole installation.
     */
    private function timezone(string $timezone): string
    {
        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }
}
