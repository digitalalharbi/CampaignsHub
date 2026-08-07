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

    public function __construct(
        private readonly DigestScope $scope,
        private readonly DailyDigest $daily,
        private readonly ProviderRegistry $providers,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * Send one person their daily digest for `$day`, or record why not.
     *
     * @return string the state written to the ledger
     */
    public function sendDaily(User $user, string $tenantId, Carbon $day, string $locale = 'ar'): string
    {
        $periodKey = $day->toDateString();

        // Claim the period first. A losing race means somebody else is already sending it.
        if (! $this->claim($tenantId, $user, 'daily', $periodKey)) {
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
            $digest = $this->daily->build($user, $tenantId, $projectIds, $day);

            if (($digest['sendable'] ?? false) !== true) {
                return $this->finish($user, 'daily', $periodKey, 'skipped', (string) ($digest['reason'] ?? 'not_sendable'));
            }

            // Honest classification, from the product's own answer about the channel — never from
            // an assumption that mail works because a mailer is configured in .env.
            if (! $this->providers->isConfigured('email')) {
                return $this->finish($user, 'daily', $periodKey, 'awaiting_credentials', 'no_email_provider');
            }

            Mail::to($user->email)->send(new DailyDigestMail($digest, $locale, (string) $user->name));

            return $this->finish($user, 'daily', $periodKey, 'sent', null, sentAt: Carbon::now());
        } catch (Throwable $e) {
            /*
             * The failure is recorded with its message and left visible.
             *
             * It is deliberately NOT retried inside this call. A digest that retries in a loop
             * turns one broken template into thousands of attempts, and the standing rule in this
             * repo is that an intermittent failure must never be hidden by a retry — the next
             * scheduled run picks it up while `attempts` is under the ceiling, and stops after.
             */
            return $this->finish($user, 'daily', $periodKey, 'failed', 'exception', error: $e->getMessage());
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
