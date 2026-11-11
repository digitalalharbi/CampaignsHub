<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Console;

use App\Domains\Notifications\Services\DigestDispatcher;
use App\Domains\Notifications\Services\DigestScope;
use App\Domains\Notifications\Support\DigestSchedule;
use App\Domains\Ops\Services\ScheduledRunRows;
use App\Domains\Tenancy\Context\TenantContext;
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

    protected $description = 'Send each recipient their daily, weekly and monthly digests at their own local hour.';

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
        // EMAIL-DECISION-REPORT-001 — a client-workspace subscriber receives the CLIENT digest (no spend, outcomes first);
        // an agency subscriber the full one. Both come from the same builder and the same scope.
        $rows = DB::table('notification_preferences')
            ->when($this->option('user') !== null, fn ($q) => $q->where('user_id', (int) $this->option('user')))
            ->get();

        foreach ($rows as $row) {
            $digests = $this->digests($row);
            $wanted = $row->digests === null ? [] : (array) json_decode((string) $row->digests, true);

            if ($digests === [] && DigestSchedule::perProjectKinds($wanted) === []) {
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

            /*
             * The window's LAST day, in the reader's own timezone — see the note above.
             *
             * Yesterday rather than today because today is still being written: a provider that syncs
             * at 03:00 would make the same email different depending on the hour it went out. Since
             * EMAIL-DAILY-WINDOW-001 this is the END of a seven-day window, not the whole report.
             */
            $day = $this->option('date') !== null
                ? Carbon::parse((string) $this->option('date'), $timezone)
                : $local->copy()->subDay();

            $locale = (string) ($row->locale ?? 'ar');
            $tenantId = (string) $row->tenant_id;
            $audience = $row->client_workspace_id !== null ? 'client' : 'agency';
            $perProject = DigestSchedule::perProjectKinds($wanted);

            /*
             * DIGESTS-CADENCE-BUDGETS-001 — which rhythms are DUE this hour, decided once, then sent for each of the two
             * audiences of the same rhythm: the cross-project «ملخص المشاريع» (the `daily`/`weekly`/`monthly` keys) and the
             * report per project (`per_project`, `per_project_weekly`, `per_project_monthly`). Each is switched on its own:
             * somebody who only wants a report per project no longer has to take the projects summary to get it.
             *
             * The weekly goes out on the chosen weekday (Sunday unless changed) and reports the seven days that ended
             * yesterday against the seven before; the monthly on the chosen day (the 1st unless changed) and reports the
             * finished calendar month against the one before it. `--force` sends every switched-on rhythm now, and the
             * idempotency key (day, ISO week, calendar month) still lets each land once.
             */
            $due = [
                'daily' => true,
                'weekly' => $this->option('force') || $local->dayOfWeekIso === $this->weekday($row),
                'monthly' => $this->option('force') || $local->day === $this->monthday($row),
            ];

            foreach (['daily', 'weekly', 'monthly'] as $kind) {
                if (! $due[$kind]) {
                    continue;
                }
                // The monthly reports the month that just finished; a forced run reports the month in progress (a manual check).
                $anchor = match ($kind) {
                    'daily' => $day->copy()->startOfDay(),
                    'weekly' => $day->copy()->endOfDay(),
                    default => $this->option('force') ? $day->copy() : DigestSchedule::previousMonth($day->copy()->addDay())['from'],
                };
                if (in_array($kind, $digests, true)) {
                    $state = $this->dispatch($dispatcher, $kind, $user, $tenantId, $anchor, $locale, $audience, null);
                    $state === 'sent' ? $sent++ : $skipped++;
                    $this->line("{$user->email} {$kind}: {$state}");
                }
                if (in_array($kind, $perProject, true)) {
                    $this->perProject($dispatcher, $user, $tenantId, $kind, $anchor, $locale, $audience, $sent, $skipped);
                }
            }
        }

        /*
         * AUTOMATION-FIRST-OPERATIONS-001 — the count the ledger is supposed to hold.
         *
         * Digests SENT. A user skipped for having nothing to say is not a row this run touched, and
         * counting them would turn a quiet day into a busy-looking one.
         *
         * Already computed, already printed to a terminal nobody watches at 04:00, while
         * `scheduled_runs.rows_affected` stayed null — so the ops page could say SUCCEEDED without
         * being able to say whether the run did anything.
         */
        app(ScheduledRunRows::class)->report($sent);

        $this->info("digests sent={$sent} other={$skipped}");

        return self::SUCCESS;
    }

    /**
     * DIGESTS-VISUAL-V2-001 / DIGESTS-CADENCE-BUDGETS-001 — one report PER PROJECT, for a reader who switched that rhythm on.
     *
     * Off by default: thirty projects would be thirty extra mails a morning, which is a choice, not a default. Every one is its
     * own ledger row (so an idempotent retry cannot double-send) and goes through the same gate, scope and unsubscribe as the
     * projects summary. The approved project template carries it on every rhythm; the monthly is the same design with month framing.
     */
    private function perProject(DigestDispatcher $dispatcher, User $user, string $tenantId, string $kind, Carbon $anchor, string $locale, string $audience, int &$sent, int &$skipped): void
    {
        if (! (bool) config('mail.operational_digests')) {
            return;
        }
        /*
         * The reader's projects are asked INSIDE their tenant: a role's permissions (`clients.view_all`) are tenant-scoped, and asked
         * from the bare scheduler they read as none — so the per-project reports silently went to nobody. `send()` sets the same
         * context for its own build; this is the question before it.
         */
        $tenants = app(TenantContext::class);
        $tenants->setTenantId($tenantId);
        try {
            $projectIds = app(DigestScope::class)->projectIdsFor($user, $tenantId);
        } finally {
            $tenants->forget();
        }
        foreach ($projectIds as $projectId) {
            $key = DigestDispatcher::projectKey((string) $projectId);
            $state = $this->dispatch($dispatcher, $kind, $user, $tenantId, $anchor, $locale, $audience, $key);
            $state === 'sent' ? $sent++ : $skipped++;
            $this->line("{$user->email} {$kind} project {$key}: {$state}");
        }
    }

    private function dispatch(DigestDispatcher $dispatcher, string $kind, User $user, string $tenantId, Carbon $anchor, string $locale, string $audience, ?string $onlyProject): string
    {
        return match ($kind) {
            'daily' => $dispatcher->sendDaily($user, $tenantId, $anchor, $locale, $audience, $onlyProject),
            'weekly' => $dispatcher->sendWeekly($user, $tenantId, $anchor, $locale, $audience, $onlyProject),
            default => $dispatcher->sendMonthly($user, $tenantId, $anchor, $locale, $audience, $onlyProject),
        };
    }

    /**
     * Which projects-summary rhythms this row asked for.
     *
     * Absent means NONE. An opt-in that defaults to on is not an opt-in, and the first scheduled run
     * after a deploy is the worst possible moment to discover that.
     *
     * @return list<string>
     */
    private function digests(object $row): array
    {
        $chosen = $row->digests === null ? [] : (array) json_decode((string) $row->digests, true);

        return array_values(array_filter(
            ['daily', 'weekly', 'monthly'],
            static fn (string $kind): bool => ($chosen[$kind] ?? false) === true,
        ));
    }

    /**
     * A stored timezone that PHP does not recognise falls back rather than throwing.
     *
     * One bad row would otherwise abort the sweep and silence everybody else's digest — a single
     * malformed preference taking down the feature for the whole installation.
     */
    /**
     * The weekday this recipient chose, ISO-8601 — EMAIL-SCHEDULE-001.
     *
     * Sunday when unset or out of range (DIGESTS-CADENCE-BUDGETS-001, see `DigestSchedule::weekday()`); a stored choice
     * is never moved. A value outside 1–7 falls back rather than silently never matching.
     */
    private function weekday(object $row): int
    {
        return DigestSchedule::weekday($row->digest_weekday ?? null);
    }

    /**
     * The day of the month this recipient chose.
     *
     * 1–28 accepted; anything else falls back to the FIRST. The migration's reason for the 28 is that
     * a report set for the 30th would never arrive in February and would do so silently.
     *
     * Delegated to `DigestSchedule` — EMAIL-SETTINGS-DEPTH-001 — so the settings screen's «next send»
     * and this sweep cannot answer differently. A screen that promises a date no email arrives on is
     * worse than one that promises nothing.
     */
    private function monthday(object $row): int
    {
        return DigestSchedule::monthday($row->digest_monthday ?? null);
    }

    private function timezone(string $timezone): string
    {
        return DigestSchedule::timezone($timezone);
    }
}
