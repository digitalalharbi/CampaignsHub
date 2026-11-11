<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Branding\Services\SharedLinkBranding;
use App\Domains\Notifications\Mail\DailyDigestMail;
use App\Domains\Notifications\Mail\OperationalDigestMail;
use App\Domains\Notifications\Providers\ProviderRegistry;
use App\Domains\Notifications\Support\AlertEventText;
use App\Domains\Notifications\Support\DigestSchedule;
use App\Domains\Notifications\Support\MailFailure;
use App\Domains\Notifications\Support\MessageCatalogue;
use App\Domains\Notifications\Support\OperationalDigestModel;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Tenancy\Context\TenantContext;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Sends one digest, once — MAIL-003.
 *
 * ## Idempotency is a database constraint, not a check
 *
 * `digest_sends` is unique on `(user_id, kind, period_key)`, and the row is claimed BEFORE the mail
 * is built. A check-then-send has a window between the two, and that window is exactly where a
 * retried queue job, an overlapping scheduler run or a second worker sends the same email twice.
 * Somebody who receives yesterday's numbers twice stops trusting the ones they receive once.
 *
 * Claiming first also means a crash between sending and recording leaves evidence that the send
 * happened, rather than a clean slate that invites a repeat.
 *
 * ## Every outcome is a recorded state
 *
 * «Why did I not get my digest?» has to have an answer, and the honest answers differ: nothing in
 * scope, no activity, no mail provider wired, or a failure with its error. `skipped` carries a
 * reason; `awaiting_credentials` is not a failure and is never retried as one.
 *
 * ## Nothing claims to have been sent that was not
 *
 * With no configured provider — the default `Null` adapter — the state is `awaiting_credentials` and
 * no mail leaves the process. `sent` is written only after `Mail::send()` returns without throwing.
 */
final class DigestDispatcher
{
    /** Beyond this a failure is left alone: retrying a broken template every hour is not a fix. */
    private const MAX_ATTEMPTS = 3;

    /** Minutes before the next try, after the first and the second failure. */
    private const BACKOFF_MINUTES = [15, 60];

    /** Ledger kind prefix of a send the person asked for, so it never competes with the scheduled one. */
    public const ON_DEMAND_PREFIX = 'now_';

    /** The daily email reports a WEEK, compared with the week before — see {@see sendDaily()}. */
    public const DAILY_WINDOW_DAYS = 7;

    public function __construct(
        private readonly DigestScope $scope,
        private readonly DailyDigest $daily,
        private readonly ProviderRegistry $providers,
        private readonly TenantContext $tenants,
        // The ONE branding resolver. Injected rather than newed so this class cannot grow a second.
        private readonly SharedLinkBranding $branding,
        // MEMBER-VISIBILITY-001 — the one authorisation engine, asked per recipient whether the money may be shown.
        private readonly ProjectAbilities $abilities,
        private readonly MailSendGate $gate,
        private readonly MailUnsubscribes $unsubscribes,
    ) {}

    /**
     * The seven days ending on `$day`, against the seven before them — EMAIL-DAILY-WINDOW-001.
     *
     * This used to report `$day` alone, which made the daily email a report on ONE day compared with
     * the day before it. Two days is the noisiest comparison paid media offers: a weekend, a payday,
     * one campaign starting or a single provider syncing late moves it enough to look like a trend,
     * and a reader who acts on that is reacting to the calendar. Seven against seven contains a whole
     * week in each side, so both windows hold the same weekdays and a change means something changed.
     *
     * What is NOT changed is the rhythm or the ledger key: this still sends once per day, keyed on
     * `$day`, so the unique index that stops a second copy keeps working exactly as before. Only the
     * span the email reports on widens. The comparison window follows automatically —
     * {@see DailyDigest::buildRange()} always takes the same number of days immediately before.
     *
     * @return string the state written to the ledger
     */
    public function sendDaily(User $user, string $tenantId, Carbon $day, string $locale = 'ar', string $audience = 'agency', ?string $onlyProject = null, bool $onDemand = false): string
    {
        $end = $day->copy()->endOfDay();
        $start = $end->copy()->subDays(self::DAILY_WINDOW_DAYS - 1)->startOfDay();

        return $this->send($user, $tenantId, 'daily', $day->toDateString().self::projectSuffix($onlyProject), $start, $end, $locale, $audience, onDemand: $onDemand, onlyProject: $onlyProject);
    }

    /**
     * The week ending on `$weekEnd`, sent once — MAIL-005.
     *
     * The period key is the ISO week (`2026-W32`) rather than a date, so a run on any day of that
     * week converges on the same row and the same unique-index guarantee. Everything else is the
     * daily path: same builder, same scope, same honest states.
     */
    public function sendWeekly(User $user, string $tenantId, Carbon $weekEnd, string $locale = 'ar', string $audience = 'agency', ?string $onlyProject = null, bool $onDemand = false): string
    {
        $end = $weekEnd->copy()->endOfDay();
        $start = $end->copy()->subDays(6)->startOfDay();

        return $this->send($user, $tenantId, 'weekly', $end->format('o-\WW').self::projectSuffix($onlyProject), $start, $end, $locale, $audience, onDemand: $onDemand, onlyProject: $onlyProject);
    }

    /**
     * EMAIL-INTELLIGENCE-001 — the completed month, sent once.
     *
     * The period key is the calendar month (`2026-08`), so a run on any day inside it converges on
     * the same ledger row and the same unique-index guarantee that already protects daily and
     * weekly. Everything else is the daily path: one builder, one scope, the same honest states.
     *
     * The window is the WHOLE month `$inMonth` falls in, not «the last 30 days». A monthly report
     * that slides is not comparable to the one before it, and comparability is the only reason to
     * send a monthly report rather than a weekly one.
     */
    public function sendMonthly(User $user, string $tenantId, Carbon $inMonth, string $locale = 'ar', string $audience = 'agency', ?string $onlyProject = null, bool $onDemand = false): string
    {
        $start = $inMonth->copy()->startOfMonth()->startOfDay();
        $end = $inMonth->copy()->endOfMonth()->endOfDay();

        // DIGESTS-CADENCE-BUDGETS-001 — the per-project monthly is its own ledger row: `2026-10~<project>`.
        return $this->send($user, $tenantId, 'monthly', $start->format('Y-m').self::projectSuffix($onlyProject), $start, $end, $locale, $audience, onDemand: $onDemand, onlyProject: $onlyProject);
    }

    /**
     * NOTIFICATION-ENROLMENT-001 — «أرسل لي الآن»: THIS digest, for THIS person's own scope, to THEIR own address, once.
     *
     * The same builder, scope, spend rule, gate, mailer and unsubscribe link as the scheduled send — that is the point: it
     * answers «would my digest reach me, and what would it say?» by running the real path, not a lookalike. What differs is
     * only the ledger identity (see `send()`), and the window: the one the scheduled run would use today for this person.
     *
     * The recipient is a parameter nobody can aim: callers pass the signed-in user, and the address is read from that user.
     *
     * @param  'daily'|'weekly'|'monthly'  $kind
     * @return array{state: string, reason: ?string}
     */
    public function sendNow(User $user, string $tenantId, string $kind, ?Carbon $now = null): array
    {
        $row = DB::table('notification_preferences')->where('tenant_id', $tenantId)->where('user_id', $user->getKey())->first();
        $timezone = $this->zone((string) ($row->timezone ?? $user->timezone ?? 'Asia/Riyadh'));
        $locale = in_array((string) ($row->locale ?? $user->locale ?? 'ar'), ['ar', 'en'], true) ? (string) ($row->locale ?? $user->locale ?? 'ar') : 'ar';
        $audience = ($row->client_workspace_id ?? null) !== null ? 'client' : 'agency';
        $day = ($now ?? Carbon::now())->copy()->setTimezone($timezone)->subDay();

        [$periodKey, $from, $to] = match ($kind) {
            'daily' => [$day->toDateString(), $day->copy()->endOfDay()->subDays(self::DAILY_WINDOW_DAYS - 1)->startOfDay(), $day->copy()->endOfDay()],
            'weekly' => [$day->copy()->endOfDay()->format('o-\WW'), $day->copy()->endOfDay()->subDays(6)->startOfDay(), $day->copy()->endOfDay()],
            default => [$day->copy()->startOfMonth()->format('Y-m'), $day->copy()->startOfMonth()->startOfDay(), $day->copy()->endOfMonth()->endOfDay()],
        };

        $state = $this->send($user, $tenantId, $kind, $periodKey, $from, $to, $locale, $audience, onDemand: true);
        $reason = DB::table('digest_sends')->where('user_id', $user->getKey())->where('kind', self::ON_DEMAND_PREFIX.$kind)
            ->where('period_key', 'like', substr($periodKey, 0, 15).'#%')->orderByDesc('created_at')->value('reason');

        return ['state' => $state, 'reason' => $reason === null ? null : (string) $reason];
    }

    private function zone(string $name): string
    {
        return in_array($name, timezone_identifiers_list(), true) ? $name : 'Asia/Riyadh';
    }

    /**
     * Try again every failed digest whose wait is over — EMAIL-DELIVERY-LEDGER-001.
     *
     * The class said «the next scheduled run picks it up», which was never true of a digest: the sweep
     * fires only at the recipient's own hour, and by then the period key has moved on, so a daily that
     * failed once simply never arrived. This re-sends the stored window through the same `send()`, which
     * means the same claim, the same scope and the same audience rule: a retry cannot show more than the
     * first attempt could, and a project lost in the meantime is gone from it. The person's choice is
     * read again too, so somebody who has since switched the digest off is not mailed.
     *
     * @return array<string,int>
     */
    public function retryDue(?Carbon $now = null): array
    {
        $rows = DB::table('digest_sends')
            ->where('status', 'failed')
            ->whereIn('kind', ['daily', 'weekly', 'monthly'])
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', $now ?? Carbon::now())
            ->whereNotNull('window_from')
            ->limit(200)
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $state = $this->retryOne($row);
            $counts[$state] = ($counts[$state] ?? 0) + 1;
        }

        return $counts;
    }

    private function retryOne(object $row): string
    {
        $preference = DB::table('notification_preferences')->where('tenant_id', $row->tenant_id)->where('user_id', $row->user_id)->first();
        $chosen = $preference === null ? [] : (array) json_decode((string) ($preference->digests ?? '[]'), true);
        $user = User::query()->find($row->user_id);

        // A report per project is wanted by ITS switch (DIGESTS-CADENCE-BUDGETS-001), the projects summary by its own.
        $perProject = str_contains((string) $row->period_key, '~');
        $wanted = $perProject
            ? in_array((string) $row->kind, DigestSchedule::perProjectKinds($chosen), true)
            : ($chosen[$row->kind] ?? false) === true;

        if ($user === null || $user->email === null || ! $wanted) {
            DB::table('digest_sends')->where('id', $row->id)->update(['next_attempt_at' => null, 'updated_at' => Carbon::now()]);

            return 'no_longer_wanted';
        }

        // A per-project digest carries its project in the period key; the retry must narrow to it again, never widen to the portfolio.
        $only = str_contains((string) $row->period_key, '~') ? substr((string) $row->period_key, (int) strpos((string) $row->period_key, '~') + 1) : null;

        return $this->send(
            $user,
            (string) $row->tenant_id,
            (string) $row->kind,
            (string) $row->period_key,
            Carbon::parse((string) $row->window_from),
            Carbon::parse((string) $row->window_to),
            (string) ($preference->locale ?? 'ar'),
            $preference->client_workspace_id !== null ? 'client' : 'agency',
            onlyProject: $only,
        );
    }

    /** One send, for any of the three rhythms. */
    private function send(
        User $user,
        string $tenantId,
        string $kind,
        string $periodKey,
        Carbon $from,
        Carbon $to,
        string $locale,
        string $audience = 'agency',
        bool $onDemand = false,
        ?string $onlyProject = null,
    ): string {
        /*
         * NOTIFICATION-ENROLMENT-001 — an on-demand send is its OWN ledger row.
         *
         * `$kind` and `$periodKey` keep meaning what they always did for the builder, the scope and the gate. The ledger
         * identity is separate: `now_daily` and a period key with a random suffix, so the person's «send me this now» can
         * never take the unique `(user, kind, period)` slot the scheduled send needs, and a scheduled send that already
         * happened never blocks a person who wants to see the mail again. `retryDue()` selects only the three scheduled
         * kinds, so an on-demand failure is shown to the person who pressed the button and never retried behind their back.
         */
        $ledgerKind = $onDemand ? self::ON_DEMAND_PREFIX.$kind : $kind;
        $ledgerKey = $onDemand ? substr($periodKey, 0, 15).'#'.strtolower(substr(bin2hex(random_bytes(4)), 0, 8)) : $periodKey;

        // Claim the period first. A losing race means somebody else is already sending it.
        if (! $this->claim($tenantId, $user, $ledgerKind, $ledgerKey, $from, $to)) {
            return 'already_sent';
        }

        /*
         * DIGESTS-CADENCE-BUDGETS-001 — the unsubscribe a report per project offers is ITS rhythm's, never the projects summary's,
         * and it is asked BEFORE anything is built: somebody who left this mail costs no queries on a one-CPU host.
         */
        $scope = $onlyProject !== null
            ? MailUnsubscribes::projectScope($kind)
            : (array_flip(MessageCatalogue::DIGEST_SWITCH)[$kind] ?? null);
        if ($this->gate->refusal($tenantId, (string) $user->email, $scope, charge: false) !== null) {
            return $this->finish($user, $ledgerKind, $ledgerKey, MailSendGate::UNSUBSCRIBED, MailSendGate::UNSUBSCRIBED);
        }

        try {
            /*
             * The tenant context is set for the duration of the build.
             *
             * Every scoped model applies `TenantScope`, which fails closed with no tenant resolved —
             * and a scheduler has none. Without this the digest would be empty rather than wrong,
             * which is the safe direction but still a silently broken feature.
             */
            $this->tenants->setTenantId($tenantId);

            $projectIds = $this->scope->projectIdsFor($user, $tenantId);
            // DIGESTS-VISUAL-V2-001 — a per-project digest narrows to ONE project of the reader's own scope; it can never widen it.
            if ($onlyProject !== null) {
                $projectIds = array_values(array_filter($projectIds, static fn ($id): bool => self::projectKey((string) $id) === $onlyProject));
            }
            // MEMBER-VISIBILITY-001 — a reader whose membership withholds the money on any project in scope receives
            // the digest without spend, cost or spend charts. Decided per recipient, from the one authorisation engine.
            if ($audience === 'agency' && ProjectCapability::exists('money.view')) {
                foreach ($projectIds as $projectId) {
                    if (! $this->abilities->allows($user, (string) $projectId, 'money.view')) {
                        $audience = 'restricted';
                        break;
                    }
                }
            }
            $digest = $this->daily->buildRange($user, $tenantId, $projectIds, $from, $to, $kind);

            /*
             * PROJECT-DIGEST-SCOPE-001 — say so when this is not everything.
             *
             * A digest covering three of somebody's twelve projects reads exactly like one covering
             * all twelve, so a reader whose client is missing cannot tell «that project was quiet»
             * from «I narrowed this months ago and forgot». Null in the ordinary case: a caveat on
             * every digest is a caveat nobody reads.
             *
             * Display only. `projectIdsFor()` above already decided what is in this email, and this
             * line cannot change it.
             */
            $digest['narrowing'] = $this->scope->narrowing($user, $tenantId);

            if (($digest['sendable'] ?? false) !== true) {
                return $this->finish($user, $ledgerKind, $ledgerKey, 'skipped', (string) ($digest['reason'] ?? 'not_sendable'));
            }

            // Honest classification, from the product's own answer about the channel — never from
            // an assumption that mail works because a mailer is configured in .env.
            if (! $this->providers->isConfigured('email')) {
                return $this->finish($user, $ledgerKind, $ledgerKey, 'awaiting_credentials', 'no_email_provider');
            }

            /*
             * BRANDING-HIERARCHY-001 — the digest goes out under the AGENCY's name, not the product's.
             *
             * Resolved through the same `SharedLinkBranding` the reports and the shared links use,
             * with a null report so it settles at the tenant layer: a digest can span several of that
             * agency's clients, and naming one of them on a summary about all of them would be wrong
             * in a way the reader could not detect.
             *
             * Only the NAME. An email cannot hide a broken image the way the report header now does —
             * there is no `onError` in an inbox — so a logo that 404s would be permanent, and shipping
             * the half that is safe is better than shipping the half that cannot be repaired.
             */
            $identity = $this->branding->forReport(null, $tenantId, static fn (): string => '');

            // EMAIL-GAPS — asked again here, at the send: a click after the sweep picked this person still counts. A digest may not
            // spend the share of the tenant's daily allowance that is kept for alerts (`mail.alert_reserve_per_tenant`).
            $refusal = $this->gate->refusal($tenantId, (string) $user->email, $scope, reserve: (int) config('mail.alert_reserve_per_tenant', 200));

            if ($refusal !== null) {
                return $this->finish($user, $ledgerKind, $ledgerKey, $refusal, $refusal);
            }

            /*
             * EMAIL-DIGESTS-OPERATIONAL-001 — the operational digest, for a reader who may see the money.
             *
             * Behind `mail.operational_digests` (off by default). A client or a member whose membership
             * withholds the money keeps the layout that carries none. One project in scope is a project
             * digest; more is the portfolio digest. Either way it is built from the SAME `$digest`
             * payload, so the scope, the permissions and the figures were already decided above.
             */
            $mailable = $audience === 'agency' && (bool) config('mail.operational_digests')
                ? $this->operationalMail($digest, $locale, (string) $user->name, $kind, $onlyProject !== null)
                : new DailyDigestMail(
                    $digest,
                    $locale,
                    (string) $user->name,
                    $kind,
                    is_string($identity['name'] ?? null) ? $identity['name'] : null,
                    $audience,
                );

            $sent = Mail::to($user->email)->send($mailable->offerUnsubscribe($this->unsubscribes->url($tenantId, (string) $user->email, $scope, (int) $user->getKey())));

            return $this->finish($user, $ledgerKind, $ledgerKey, 'sent', null, sentAt: Carbon::now(), messageId: TransactionalMailer::messageId($sent));
        } catch (Throwable $e) {
            /*
             * The failure is recorded with its reason and left visible.
             *
             * It is deliberately NOT retried inside this call: a digest that retries in a loop turns one
             * broken template into thousands of attempts. `retryDue()` comes back after the backoff,
             * unless a retry cannot help or the ceiling is reached. The credential a transport may
             * quote is removed first (MailFailure).
             */
            $failure = MailFailure::from($e);
            $attempts = (int) DB::table('digest_sends')->where('user_id', $user->getKey())->where('kind', $ledgerKind)->where('period_key', $ledgerKey)->value('attempts');
            $next = $attempts < self::MAX_ATTEMPTS && ! MailFailure::isPermanent($failure['code'])
                ? Carbon::now()->addMinutes(self::BACKOFF_MINUTES[$attempts - 1] ?? 60)
                : null;

            return $this->finish($user, $ledgerKind, $ledgerKey, 'failed', 'exception', error: $failure['safe'], failureCode: $failure['code'], nextAttemptAt: $next);
        } finally {
            $this->tenants->forget();
        }
    }

    /**
     * @param  array<string,mixed>  $digest  the payload of `DailyDigest::buildRange()`
     */
    private function operationalMail(array $digest, string $locale, string $name, string $kind, bool $perProject = false): OperationalDigestMail
    {
        $projects = array_values((array) ($digest['projects'] ?? []));

        if (count($projects) === 1 || ($perProject && $projects !== [])) {
            return new OperationalDigestMail(OperationalDigestModel::project($projects[0], $locale, (int) ($digest['days'] ?? 7), $kind), $locale, $name, $kind, 'project', self::periodLabel($digest));
        }

        return new OperationalDigestMail(OperationalDigestModel::portfolio($digest, $locale, $kind), $locale, $name, $kind, 'portfolio', self::periodLabel($digest));
    }

    /** The twelve hex characters that name a project inside a ledger period key (the column is 24 wide). */
    public static function projectKey(string $projectId): string
    {
        return substr(str_replace('-', '', $projectId), 0, 12);
    }

    private static function projectSuffix(?string $onlyProject): string
    {
        return $onlyProject === null ? '' : '~'.$onlyProject;
    }

    /** «2026-11-03 – 2026-11-09», each date isolated left-to-right so an Arabic line does not print it backwards. */
    private static function periodLabel(array $digest): ?string
    {
        if (! isset($digest['date'], $digest['to_date'])) {
            return null;
        }

        return AlertEventText::ltr((string) $digest['date']).' – '.AlertEventText::ltr((string) $digest['to_date']);
    }

    /**
     * Reserve this period for this person, or report that it is already taken.
     *
     * The unique index does the work: a second inserter gets a constraint violation, which is the
     * answer rather than an error. A `failed` row under the attempt ceiling is re-claimed, because
     * a transient failure should be retried by the next run — once.
     */
    private function claim(string $tenantId, User $user, string $kind, string $periodKey, Carbon $from, Carbon $to): bool
    {
        $existing = DB::table('digest_sends')
            ->where('user_id', $user->getKey())
            ->where('kind', $kind)
            ->where('period_key', $periodKey)
            ->first();

        if ($existing !== null) {
            $retryable = $existing->status === 'failed' && (int) $existing->attempts < self::MAX_ATTEMPTS;

            if (! $retryable) {
                return false;
            }

            // Conditional, so the sweep and the retry command cannot both win one failed row.
            return DB::table('digest_sends')->where('id', $existing->id)->where('status', 'failed')->update([
                'status' => 'claimed',
                'attempts' => (int) $existing->attempts + 1,
                'attempted_at' => Carbon::now(),
                'next_attempt_at' => null,
                'updated_at' => Carbon::now(),
            ]) === 1;
        }

        try {
            DB::table('digest_sends')->insert([
                'id' => (string) Uuid::uuid4(),
                'tenant_id' => $tenantId,
                'user_id' => $user->getKey(),
                'kind' => $kind,
                'period_key' => $periodKey,
                'status' => 'claimed',
                'attempts' => 1,
                'attempted_at' => Carbon::now(),
                'window_from' => $from,
                'window_to' => $to,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            return true;
        } catch (Throwable) {
            // Somebody else claimed it between the read and the insert. That is the index doing
            // exactly its job, and it is not an error.
            return false;
        }
    }

    private function finish(
        User $user,
        string $kind,
        string $periodKey,
        string $status,
        ?string $reason = null,
        ?string $error = null,
        ?Carbon $sentAt = null,
        ?string $messageId = null,
        ?string $failureCode = null,
        ?Carbon $nextAttemptAt = null,
    ): string {
        DB::table('digest_sends')
            ->where('user_id', $user->getKey())
            ->where('kind', $kind)
            ->where('period_key', $periodKey)
            ->update([
                'status' => $status,
                'reason' => $reason,
                'last_error' => $error,
                'sent_at' => $sentAt,
                'accepted_at' => $status === 'sent' ? $sentAt : null,
                'provider_message_id' => $messageId,
                'failure_code' => $failureCode,
                'next_attempt_at' => $nextAttemptAt,
                'updated_at' => Carbon::now(),
            ]);

        return $status;
    }
}
