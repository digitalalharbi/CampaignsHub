<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Notifications\Mail\AlertBundleMail;
use App\Domains\Notifications\Providers\ProviderRegistry;
use App\Domains\Notifications\Support\MessageCatalogue;
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
        private readonly NotificationChoices $choices,
    ) {}

    /*
     * A note's kind and its category used to be a map here — MAIL-010.
     *
     * It moved to `MessageCatalogue` in MAIL-011, because the same eight kinds were also being
     * classified by the preferences screen and by the bell, and three copies of one mapping is three
     * chances for a message to be filed under a category its own switch does not control.
     */

    /**
     * Send whatever this recipient needs to hear about today, and record what was sent.
     *
     * @param  list<string>|null  $onlyProjectIds  narrows to a manager's arrangement — NEVER widens,
     *                                             because it is intersected with the person's own ceiling
     * @param  list<string>|null  $onlyCategories  likewise: a subset of what they would otherwise get
     * @return array<string,int> what happened, by state — for the console and the tests
     */
    public function sweep(
        User $user,
        string $tenantId,
        Carbon $day,
        string $locale = 'ar',
        ?array $onlyProjectIds = null,
        ?array $onlyCategories = null,
    ): array {
        $counts = [
            'sent' => 0, 'already_sent' => 0, 'skipped' => 0, 'awaiting_credentials' => 0, 'failed' => 0,
            // MAIL-011 — two reasons a finding produced no email, kept apart from `skipped` because
            // «you switched this off» and «you asked for it in tomorrow's digest» are different
            // answers to «why did nobody tell me?», and the console is where that gets read.
            'switched_off' => 0, 'held_for_digest' => 0, 'held_by_quiet_hours' => 0,
        ];

        /** @var list<array{severity: string, title: string, detail: string, context: string, period_key: string}> $eligible */
        $eligible = [];
        $userId = (int) $user->getKey();

        try {
            $this->tenants->setTenantId($tenantId);

            $projectIds = $this->scope->projectIdsFor($user, $tenantId);

            /*
             * Intersection, not replacement.
             *
             * The narrowing argument arrives from a recipient arrangement, which is a REQUEST rather
             * than a grant (MAIL-010). Assigning it here instead of intersecting would turn this
             * parameter into a way to mail somebody a project they cannot reach — the single thing
             * the whole recipient design exists to prevent.
             */
            if ($onlyProjectIds !== null) {
                $projectIds = array_values(array_intersect($projectIds, $onlyProjectIds));
            }

            if ($projectIds === []) {
                return $counts;
            }

            $digest = $this->daily->buildRange($user, $tenantId, $projectIds, $day->copy()->startOfDay(), $day->copy()->endOfDay());

            foreach ($digest['projects'] ?? [] as $block) {
                foreach ($block['observations'] ?? [] as $note) {
                    if (! in_array($note['severity'] ?? '', self::ALERTABLE, true)) {
                        continue;
                    }

                    $kind = (string) ($note['kind'] ?? '');

                    if ($onlyCategories !== null && ! in_array(
                        MessageCatalogue::categoryOfNote($kind),
                        array_map(MessageCatalogue::normaliseCategory(...), $onlyCategories),
                        true,
                    )) {
                        continue;
                    }

                    /*
                     * The person's own switch for THIS message — MAIL-011.
                     *
                     * `chose()` rather than `wants()`: `digests.alerts = true` is somebody asking for
                     * findings as they happen, and applying the catalogue's per-type defaults on top
                     * of that would quietly deliver a subset of what they asked for. Only an explicit
                     * «no» stops a message here.
                     */
                    if ($this->choices->chose($userId, $tenantId, $kind, 'email') === false) {
                        $counts['switched_off']++;

                        continue;
                    }

                    /*
                     * «Not now, in the digest.»
                     *
                     * The daily and weekly digests already print every observation for the period, so
                     * a person who set this kind to `daily` genuinely receives it — later, in the
                     * summary — rather than losing it. That is why the rhythm is honoured by skipping
                     * here and nothing else needs to change.
                     */
                    if ($this->choices->rhythm($userId, $tenantId, $kind) !== 'immediate') {
                        $counts['held_for_digest']++;

                        continue;
                    }

                    /*
                     * Their night, not the server's — MAIL-013.
                     *
                     * Nothing is CLAIMED here, which is what makes holding safe: the finding is
                     * simply not sent this sweep, and the next sweep after the window closes finds
                     * it again and sends it. No queue, no held-message table, and no risk of a
                     * message that was parked and then forgotten.
                     *
                     * If the finding has stopped being true by morning, it is never sent — which is
                     * the right outcome. An alert is «this needs a decision»; there is no decision
                     * left to make about a budget that came back into line overnight.
                     */
                    if ($this->choices->inQuietHours($userId, $tenantId)) {
                        $counts['held_by_quiet_hours']++;

                        continue;
                    }

                    $eligible[] = [
                        'severity' => (string) $note['severity'],
                        'title' => (string) $note['title'],
                        'detail' => (string) $note['detail'],
                        'context' => (string) ($block['project_name'] ?? ''),
                        'period_key' => $this->periodKey($block, $note, $day),
                    ];
                }
            }

            foreach ($this->deliver($user, $tenantId, $eligible, $locale) as $state => $n) {
                $counts[$state] = ($counts[$state] ?? 0) + $n;
            }

            return $counts;
        } finally {
            $this->tenants->forget();
        }
    }

    /**
     * One message carrying everything this sweep found — MAIL-013.
     *
     * ## Why the claims stay per finding while the email is one
     *
     * The cooldown is a statement about a FINDING: «this budget is still running ahead» should not be
     * repeated for three days. The email is a statement about a MOMENT: «here is what needs a
     * decision now». Collapsing the claims into one would make the whole bulletin share a cooldown,
     * so a new finding tomorrow would be silenced by an unrelated one sent today.
     *
     * So each finding is claimed on its own, exactly as before, and only the ones that CLAIM
     * successfully go into the message. `already_sent` is still counted per finding.
     *
     * ## All or none, and it is the ledger that says which
     *
     * One send either succeeds for every finding in it or fails for every finding in it — so all the
     * claimed rows are finished with the same state. A failure leaves them `failed`, and the next
     * sweep re-claims them (up to three attempts) rather than this one retrying in a loop.
     *
     * @param  list<array{severity: string, title: string, detail: string, context: string, period_key: string}>  $eligible
     * @return array<string,int>
     */
    private function deliver(User $user, string $tenantId, array $eligible, string $locale): array
    {
        $counts = [];
        $claimed = [];

        foreach ($eligible as $item) {
            if ($this->claim($tenantId, $user, $item['period_key'])) {
                $claimed[] = $item;
            } else {
                $counts['already_sent'] = ($counts['already_sent'] ?? 0) + 1;
            }
        }

        if ($claimed === []) {
            return $counts;
        }

        $keys = array_column($claimed, 'period_key');

        // Honest classification, from the product's own answer about the channel — never from an
        // assumption that mail works because a mailer is configured in .env.
        if (! $this->providers->isConfigured('email')) {
            $counts['awaiting_credentials'] = $this->finishAll($user, $keys, 'awaiting_credentials', 'no_email_provider');

            return $counts;
        }

        try {
            Mail::to($user->email)->send(new AlertBundleMail(
                items: array_map(
                    static fn (array $item): array => [
                        'severity' => $item['severity'],
                        'title' => $item['title'],
                        'detail' => $item['detail'],
                        'context' => $item['context'],
                    ],
                    $claimed,
                ),
                lang: $locale,
                recipientName: (string) $user->name,
            ));

            $counts['sent'] = $this->finishAll($user, $keys, 'sent', null, Carbon::now());
        } catch (Throwable $e) {
            /*
             * Recorded and left alone, never retried in a loop.
             *
             * The standing rule in this repo is that an intermittent failure must not be hidden by a
             * retry; the next sweep re-claims a failed row and this one stops.
             */
            $counts['failed'] = $this->finishAll($user, $keys, 'failed', 'exception', error: $e->getMessage());
        }

        return $counts;
    }

    /**
     * @param  list<string>  $periodKeys
     * @return int how many findings were finished, which is what the counts report
     */
    private function finishAll(User $user, array $periodKeys, string $status, ?string $reason = null, ?Carbon $sentAt = null, ?string $error = null): int
    {
        foreach ($periodKeys as $key) {
            $this->finish($user, $key, $status, $reason, $sentAt, $error);
        }

        return count($periodKeys);
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
