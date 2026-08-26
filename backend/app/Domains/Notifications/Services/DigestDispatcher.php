<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Notifications\Mail\DailyDigestMail;
use App\Domains\Notifications\Providers\ProviderRegistry;
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

    /** The daily email reports a WEEK, compared with the week before — see {@see sendDaily()}. */
    public const DAILY_WINDOW_DAYS = 7;

    public function __construct(
        private readonly DigestScope $scope,
        private readonly DailyDigest $daily,
        private readonly ProviderRegistry $providers,
        private readonly TenantContext $tenants,
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
    public function sendDaily(User $user, string $tenantId, Carbon $day, string $locale = 'ar'): string
    {
        $end = $day->copy()->endOfDay();
        $start = $end->copy()->subDays(self::DAILY_WINDOW_DAYS - 1)->startOfDay();

        return $this->send($user, $tenantId, 'daily', $day->toDateString(), $start, $end, $locale);
    }

    /**
     * The week ending on `$weekEnd`, sent once — MAIL-005.
     *
     * The period key is the ISO week (`2026-W32`) rather than a date, so a run on any day of that
     * week converges on the same row and the same unique-index guarantee. Everything else is the
     * daily path: same builder, same scope, same honest states.
     */
    public function sendWeekly(User $user, string $tenantId, Carbon $weekEnd, string $locale = 'ar'): string
    {
        $end = $weekEnd->copy()->endOfDay();
        $start = $end->copy()->subDays(6)->startOfDay();

        return $this->send($user, $tenantId, 'weekly', $end->format('o-\WW'), $start, $end, $locale);
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
    public function sendMonthly(User $user, string $tenantId, Carbon $inMonth, string $locale = 'ar'): string
    {
        $start = $inMonth->copy()->startOfMonth()->startOfDay();
        $end = $inMonth->copy()->endOfMonth()->endOfDay();

        return $this->send($user, $tenantId, 'monthly', $start->format('Y-m'), $start, $end, $locale);
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
    ): string {

        // Claim the period first. A losing race means somebody else is already sending it.
        if (! $this->claim($tenantId, $user, $kind, $periodKey)) {
            return 'already_sent';
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
            $digest = $this->daily->buildRange($user, $tenantId, $projectIds, $from, $to);

            if (($digest['sendable'] ?? false) !== true) {
                return $this->finish($user, $kind, $periodKey, 'skipped', (string) ($digest['reason'] ?? 'not_sendable'));
            }

            // Honest classification, from the product's own answer about the channel — never from
            // an assumption that mail works because a mailer is configured in .env.
            if (! $this->providers->isConfigured('email')) {
                return $this->finish($user, $kind, $periodKey, 'awaiting_credentials', 'no_email_provider');
            }

            Mail::to($user->email)->send(new DailyDigestMail($digest, $locale, (string) $user->name, $kind));

            return $this->finish($user, $kind, $periodKey, 'sent', null, sentAt: Carbon::now());
        } catch (Throwable $e) {
            /*
             * The failure is recorded with its message and left visible.
             *
             * It is deliberately NOT retried inside this call. A digest that retries in a loop
             * turns one broken template into thousands of attempts, and the standing rule in this
             * repo is that an intermittent failure must never be hidden by a retry — the next
             * scheduled run picks it up while `attempts` is under the ceiling, and stops after.
             */
            return $this->finish($user, $kind, $periodKey, 'failed', 'exception', error: $e->getMessage());
        } finally {
            $this->tenants->forget();
        }
    }

    /**
     * Reserve this period for this person, or report that it is already taken.
     *
     * The unique index does the work: a second inserter gets a constraint violation, which is the
     * answer rather than an error. A `failed` row under the attempt ceiling is re-claimed, because
     * a transient failure should be retried by the next run — once.
     */
    private function claim(string $tenantId, User $user, string $kind, string $periodKey): bool
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

            DB::table('digest_sends')->where('id', $existing->id)->update([
                'status' => 'claimed',
                'attempts' => (int) $existing->attempts + 1,
                'updated_at' => Carbon::now(),
            ]);

            return true;
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
                'updated_at' => Carbon::now(),
            ]);

        return $status;
    }
}
