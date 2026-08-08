<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Notifications\Mail\OperationalMail;
use App\Domains\Notifications\Providers\ProviderRegistry;
use App\Domains\Tenancy\Context\TenantContext;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Immediate alerts — MAIL-006.
 *
 * ## The alerts are the digest's own notes, sent early
 *
 * `ReportObservations` already decides what is worth saying about a project's figures, with
 * thresholds that are named and argued. An alert is one of those notes that could not wait until
 * tomorrow morning — so this class chooses WHICH of them to send now and WHEN to stop repeating,
 * and invents no findings of its own.
 *
 * That is deliberate. A second detector with its own thresholds would let the alert and the digest
 * disagree about whether a campaign is overspending, and the reader has no way to tell which is
 * right.
 *
 * ## Why the ledger is the deduplication, again
 *
 * `digest_sends` is unique on `(user_id, kind, period_key)`. An alert writes `kind = 'alert'` and a
 * period key built from the note, the project and the COOLDOWN BUCKET the current moment falls in.
 * The same overspending campaign therefore produces one email today and, if it is still overspending
 * in three days, one more — never one an hour, which is how a useful alert becomes a filter rule.
 *
 * No new table, and no check-then-send window: the same unique index that made the digest idempotent
 * makes this idempotent, for exactly the same reason.
 *
 * ## What is NOT alerted
 *
 * `info` notes. «Two metrics are not reported by your platforms» is worth reading beside the figures
 * and is not worth an interruption; sending it would train the reader to ignore the ones that are.
 */
final class AlertDispatcher
{
    /**
     * How long the same finding stays quiet after it has been sent.
     *
     * Three days rather than one: a budget running ahead of plan is still running ahead tomorrow,
     * and repeating it every morning says nothing new while costing the reader the same attention.
     */
    private const COOLDOWN_DAYS = 3;

    /** Only these reach an inbox on their own. Everything else waits for the digest. */
    private const ALERTABLE = ['critical', 'warning'];

    public function __construct(
        private readonly DigestScope $scope,
        private readonly DailyDigest $daily,
        private readonly ProviderRegistry $providers,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * Send whatever this recipient needs to hear about today, and record what was sent.
     *
     * @return array<string,int> what happened, by state — for the console and the tests
     */
    public function sweep(User $user, string $tenantId, Carbon $day, string $locale = 'ar'): array
    {
        $counts = ['sent' => 0, 'already_sent' => 0, 'skipped' => 0, 'awaiting_credentials' => 0, 'failed' => 0];

        try {
            $this->tenants->setTenantId($tenantId);

            $projectIds = $this->scope->projectIdsFor($user, $tenantId);
            if ($projectIds === []) {
                return $counts;
            }

            $digest = $this->daily->buildRange($user, $tenantId, $projectIds, $day->copy()->startOfDay(), $day->copy()->endOfDay());

            foreach ($digest['projects'] ?? [] as $block) {
                foreach ($block['observations'] ?? [] as $note) {
                    if (! in_array($note['severity'] ?? '', self::ALERTABLE, true)) {
                        continue;
                    }

                    $state = $this->send($user, $tenantId, $block, $note, $day, $locale);
                    $counts[$state] = ($counts[$state] ?? 0) + 1;
                }
            }

            return $counts;
        } finally {
            $this->tenants->forget();
        }
    }

    /** @param array<string,mixed> $block @param array<string,mixed> $note */
    private function send(User $user, string $tenantId, array $block, array $note, Carbon $day, string $locale): string
    {
        $periodKey = $this->periodKey($block, $note, $day);

        if (! $this->claim($tenantId, $user, $periodKey)) {
            return 'already_sent';
        }

        try {
            // Honest classification, from the product's own answer about the channel — never from an
            // assumption that mail works because a mailer is configured in .env.
            if (! $this->providers->isConfigured('email')) {
                return $this->finish($user, $periodKey, 'awaiting_credentials', 'no_email_provider');
            }

            Mail::to($user->email)->send(new OperationalMail(
                kind: 'alert',
                severity: (string) $note['severity'],
                title: (string) $note['title'],
                detail: (string) $note['detail'],
                context: (string) ($block['project_name'] ?? ''),
                lang: $locale,
                recipientName: (string) $user->name,
            ));

            return $this->finish($user, $periodKey, 'sent', null, Carbon::now());
        } catch (Throwable $e) {
            /*
             * Recorded and left alone, never retried in a loop.
             *
             * The standing rule in this repo is that an intermittent failure must not be hidden by a
             * retry; the next sweep re-claims a failed row and this one stops.
             */
            return $this->finish($user, $periodKey, 'failed', 'exception', error: $e->getMessage());
        }
    }

    /**
     * The identity of «this finding, about this project, in this cooldown window».
     *
     * The bucket is integer division of the day number, so every day inside one window produces the
     * same key and collides on the index. It is not «now minus three days», which would slide
     * forward on every send and never actually stop anything.
     *
     * It is HASHED rather than truncated. `period_key` holds 24 characters and a project id alone is
     * a 36-character UUID, so the readable form was cut off inside the id — which does not merely
     * look wrong, it makes two projects whose ids share a prefix share a cooldown, and one of them
     * silently never gets its alert. A digest of the tuple fits and cannot collide by accident.
     */
    private function periodKey(array $block, array $note, Carbon $day): string
    {
        $bucket = intdiv((int) $day->copy()->startOfDay()->diffInDays(Carbon::parse('2020-01-01')), self::COOLDOWN_DAYS);

        return substr(hash('sha256', sprintf(
            '%s|%s|%d',
            (string) ($block['project_id'] ?? ''),
            (string) ($note['id'] ?? 'note'),
            $bucket,
        )), 0, 24);
    }

    private function claim(string $tenantId, User $user, string $periodKey): bool
    {
        $existing = DB::table('digest_sends')
            ->where('user_id', $user->getKey())
            ->where('kind', 'alert')
            ->where('period_key', $periodKey)
            ->first();

        if ($existing !== null) {
            // A failure is re-attempted once by the next sweep; a delivered alert never is.
            if ($existing->status !== 'failed' || (int) $existing->attempts >= 3) {
                return false;
            }

            DB::table('digest_sends')->where('id', $existing->id)->update([
                'status' => 'claimed', 'attempts' => (int) $existing->attempts + 1, 'updated_at' => Carbon::now(),
            ]);

            return true;
        }

        try {
            DB::table('digest_sends')->insert([
                'id' => (string) Uuid::uuid4(),
                'tenant_id' => $tenantId,
                'user_id' => $user->getKey(),
                'kind' => 'alert',
                'period_key' => $periodKey,
                'status' => 'claimed',
                'attempts' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            return true;
        } catch (Throwable) {
            // The index doing its job. Somebody else claimed it between the read and the insert.
            return false;
        }
    }

    private function finish(User $user, string $periodKey, string $status, ?string $reason = null, ?Carbon $sentAt = null, ?string $error = null): string
    {
        DB::table('digest_sends')
            ->where('user_id', $user->getKey())
            ->where('kind', 'alert')
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
