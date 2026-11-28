<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Console;

use App\Domains\Audit\AuditLogger;
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
        {--tenant= : Limit to one workspace id}
        {--role-defaults : Also list (and with --apply, set) the role defaults on rows that already exist (NOTIFY-ROLE-DEFAULTS-001)}';

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
        $aligned = [];
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
                /*
                 * NOTIFY-ROLE-DEFAULTS-001 — an existing row is a recorded choice and is never touched by default. With
                 * `--role-defaults` the owner can SEE how each row differs from its role's defaults (dry run) and, with `--apply`,
                 * align only the digest switches of those rows, one audit row each with the before and after. Nothing else in the
                 * row (channels, hour, language, project narrowing) changes.
                 */
                if ($this->option('role-defaults')) {
                    $this->alignRole($enrolment, $user, $tenantId, (string) ($tenantNames[$tenantId] ?? $tenantId), $apply, $aligned);
                }

                continue;
            }

            $verdict = $enrolment->verdict($user, $tenantId);

            if (! $verdict['eligible']) {
                $skipped[(string) $verdict['reason']] = ($skipped[(string) $verdict['reason']] ?? 0) + 1;

                continue;
            }

            $d = $enrolment->defaultsFor($user, $tenantId);
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

        if ($aligned !== []) {
            $this->table(['workspace', 'email', 'role', 'digests now', 'role default'], $aligned);
        }

        foreach ($skipped as $reason => $n) {
            $this->line("not eligible ({$reason}): {$n}");
        }

        $this->info($apply
            ? "enrolled={$summary['enrolled']} already_enrolled={$summary['already_enrolled']} not_eligible=".array_sum($skipped)
            : "would_enrol={$summary['would_enrol']} already_enrolled={$summary['already_enrolled']} not_eligible=".array_sum($skipped).' (dry run, nothing written)');

        return self::SUCCESS;
    }

    /** @param  list<list<string>>  $aligned */
    private function alignRole(EnrolMemberNotifications $enrolment, User $user, string $tenantId, string $tenantName, bool $apply, array &$aligned): void
    {
        $role = $enrolment->roleKey($user, $tenantId);
        if ($role === null) {
            return;
        }
        $row = DB::table('notification_preferences')->where('tenant_id', $tenantId)->where('user_id', $user->getKey())->first();
        $now = $row === null || $row->digests === null ? [] : (array) json_decode((string) $row->digests, true);
        $want = EnrolMemberNotifications::ROLE_DIGESTS[$role];
        $next = array_merge($now, $want);
        if ($next == $now) {
            return;
        }
        $on = static fn (array $d): string => implode('+', array_keys(array_filter($d, static fn ($v): bool => $v === true)));
        $aligned[] = [$tenantName, MailAddress::mask((string) $user->email), $role, $on($now) ?: '—', $on($next)];
        if (! $apply) {
            return;
        }
        DB::table('notification_preferences')->where('tenant_id', $tenantId)->where('user_id', $user->getKey())
            ->update(['digests' => json_encode($next), 'updated_at' => now()]);
        app(AuditLogger::class)->log(
            action: 'notifications.role_defaults_applied',
            entityType: User::class,
            entityId: (string) $user->getKey(),
            before: ['digests' => $now],
            after: ['digests' => $next, 'role' => $role],
            reason: 'role_defaults',
            userId: null,
            tenantId: $tenantId,
        );
    }
}
