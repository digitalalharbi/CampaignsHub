<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Console;

use App\Domains\Notifications\Services\EnrolMemberNotifications;
use App\Domains\Notifications\Support\MailAddress;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * NOTIFICATION-ENROLMENT-001 — open the notification row of every colleague who has none.
 *
 * ## Dry-run by default, and why that is not a convenience
 *
 * Enrolling a person is what makes this product begin emailing them. Run against Production it
 * mails real people who did not ask. So the command WRITES NOTHING unless `--apply` is given, and the
 * dry run prints exactly who would be enrolled — workspace, masked address, role, language and the
 * defaults they would start with — so the owner can read the list before approving the run.
 *
 * `--apply` opens the rows through the same service every hook uses (idempotent, never overwrites a
 * row, one audit row per person) and prints a summary. Running it twice enrols nobody twice.
 */
final class EnrolMembers extends Command
{
    protected $signature = 'notifications:enrol-members
        {--apply : Actually enrol. Without this nothing is written.}
        {--tenant= : Limit to one workspace id}';

    protected $description = 'List (default) or enrol (--apply) the staff members who have no notification preferences yet.';

    public function handle(EnrolMemberNotifications $enrolment): int
    {
        $apply = (bool) $this->option('apply');

        $pairs = DB::table('memberships')
            ->where('status', 'active')->whereIn('portal', EnrolMemberNotifications::STAFF_PORTALS)
            ->when($this->option('tenant') !== null, fn ($q) => $q->where('tenant_id', (string) $this->option('tenant')))
            ->select('tenant_id', 'user_id')->distinct()->orderBy('tenant_id')->orderBy('user_id')->get();

        $tenantNames = DB::table('tenants')->whereIn('id', $pairs->pluck('tenant_id')->unique()->all())->pluck('name', 'id');
        $this->line($apply ? 'APPLY — enrolling.' : 'DRY RUN — nothing is written. Add --apply to enrol.');

        $summary = ['would_enrol' => 0, 'enrolled' => 0, 'already_enrolled' => 0];
        $skipped = [];
        $rows = [];

        foreach ($pairs as $pair) {
            $tenantId = (string) $pair->tenant_id;
            $user = User::query()->find($pair->user_id);

            if ($user === null) {
                continue;
            }

            if ($enrolment->hasRow((int) $user->getKey(), $tenantId)) {
                $summary['already_enrolled']++;

                continue;
            }

            $verdict = $enrolment->verdict($user, $tenantId);

            if (! $verdict['eligible']) {
                $skipped[(string) $verdict['reason']] = ($skipped[(string) $verdict['reason']] ?? 0) + 1;

                continue;
            }

            $d = $enrolment->defaultsFor($user);
            $on = implode('+', array_keys(array_filter($d['digests'])));
            $rows[] = [(string) ($tenantNames[$tenantId] ?? $tenantId), MailAddress::mask((string) $user->email), (string) $verdict['role'],
                $d['locale'], $d['timezone'].' @'.$d['digest_hour'].':00', 'in-app+email', $on];

            if ($apply) {
                $state = $enrolment->enrol($user, $tenantId, 'backfill_command');
                $summary[$state === 'enrolled' ? 'enrolled' : 'already_enrolled']++;
            } else {
                $summary['would_enrol']++;
            }
        }

        if ($rows !== []) {
            $this->table(['workspace', 'email', 'role', 'locale', 'timezone @ digest hour', 'channels', 'digests on'], $rows);
        }

        foreach ($skipped as $reason => $n) {
            $this->line("not eligible ({$reason}): {$n}");
        }

        $this->info($apply
            ? "enrolled={$summary['enrolled']} already_enrolled={$summary['already_enrolled']} not_eligible=".array_sum($skipped)
            : "would_enrol={$summary['would_enrol']} already_enrolled={$summary['already_enrolled']} not_eligible=".array_sum($skipped).' (dry run, nothing written)');

        return self::SUCCESS;
    }
}
