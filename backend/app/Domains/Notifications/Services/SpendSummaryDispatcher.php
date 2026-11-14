<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\Notifications\Mail\SpendSummaryMail;
use App\Domains\Notifications\Models\ExternalDigestRecipient;
use App\Domains\Notifications\Providers\ProviderRegistry;
use App\Domains\Notifications\Support\DigestSchedule;
use App\Domains\Notifications\Support\MailAddress;
use App\Domains\Notifications\Support\MailFailure;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * SPEND-SUMMARY-EXTERNAL-RECIPIENTS-001 — sends «ملخص الإنفاق» once per period: to a workspace reader who switched it on, and to
 * the recipients outside the platform each workspace added, for exactly the projects bound to each.
 *
 * Every send is claimed first (a unique ledger row), asked of the send gate (unsubscribed? the tenant's daily allowance, which
 * keeps the alert reserve), and recorded with an honest state. A recipient outside the platform is also written to the audit
 * log, so «who received what about which project» always has an answer. Nothing claims `sent` that `Mail::send()` did not return.
 */
final class SpendSummaryDispatcher
{
    /** The workspace reader's switch, and the scope their unsubscribe link carries. */
    public const SWITCH = MailUnsubscribes::SPEND;

    public function __construct(
        private readonly ProjectSpendSummary $summary,
        private readonly DigestScope $scope,
        private readonly ProviderRegistry $providers,
        private readonly TenantContext $tenants,
        private readonly MailSendGate $gate,
        private readonly MailUnsubscribes $unsubscribes,
        private readonly ProjectAbilities $abilities,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The window a rhythm reports, in the reader's calendar: daily = yesterday; weekly = the 7 complete days ending yesterday;
     * monthly = the last complete month. Returns [from, to, period key] — the key names the window (daily: the day; weekly: «W» + its first day; monthly: the month).
     *
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    public static function window(string $kind, Carbon $localNow): array
    {
        $yesterday = $localNow->copy()->subDay();

        return match ($kind) {
            'daily' => [$yesterday->copy()->startOfDay(), $yesterday->copy()->endOfDay(), $yesterday->toDateString()],
            'monthly' => (function () use ($localNow): array {
                $m = DigestSchedule::previousMonth($localNow->copy());

                return [$m['from']->copy()->startOfDay(), $m['to']->copy()->endOfDay(), $m['from']->format('Y-m')];
            })(),
            // Keyed on the window itself, so a forced mid-week run can never take the scheduled week's slot.
            default => [$yesterday->copy()->subDays(6)->startOfDay(), $yesterday->copy()->endOfDay(), 'W'.$yesterday->copy()->subDays(6)->toDateString()],
        };
    }

    /** The workspace reader's weekly «ملخص الإنفاق», over the projects of their own scope. */
    public function sendInternal(User $user, string $tenantId, Carbon $localNow, string $locale = 'ar'): string
    {
        [$from, $to, $key] = self::window('weekly', $localNow);

        if (! $this->claimInternal($tenantId, $user, $key, $from, $to)) {
            return 'already_sent';
        }
        if ($this->gate->refusal($tenantId, (string) $user->email, self::SWITCH, charge: false) !== null) {
            return $this->finishInternal($user, $key, MailSendGate::UNSUBSCRIBED, MailSendGate::UNSUBSCRIBED);
        }

        try {
            $this->tenants->setTenantId($tenantId);
            $projectIds = $this->scope->projectIdsFor($user, $tenantId);
            // Money is shown only to a reader who may see it on EVERY project in scope (MEMBER-VISIBILITY-001).
            if (ProjectCapability::exists('money.view')) {
                foreach ($projectIds as $projectId) {
                    if (! $this->abilities->allows($user, (string) $projectId, 'money.view')) {
                        return $this->finishInternal($user, $key, 'skipped', 'money_not_visible');
                    }
                }
            }
            if ($projectIds === []) {
                return $this->finishInternal($user, $key, 'skipped', 'no_projects_in_scope');
            }
            if (! $this->providers->isConfigured('email')) {
                return $this->finishInternal($user, $key, 'awaiting_credentials', 'no_email_provider');
            }
            if (($refusal = $this->gate->refusal($tenantId, (string) $user->email, self::SWITCH, reserve: (int) config('mail.alert_reserve_per_tenant', 200))) !== null) {
                return $this->finishInternal($user, $key, $refusal, $refusal);
            }

            $data = $this->summary->build($tenantId, $projectIds, $from, $to, $localNow->copy()->startOfDay());
            $mail = (new SpendSummaryMail($data, $locale, 'weekly'))->offerUnsubscribe($this->unsubscribes->url($tenantId, (string) $user->email, self::SWITCH, (int) $user->getKey()));
            $sent = Mail::to($user->email)->send($mail);

            return $this->finishInternal($user, $key, 'sent', null, TransactionalMailer::messageId($sent));
        } catch (Throwable $e) {
            return $this->finishInternal($user, $key, 'failed', MailFailure::from($e)['code'] ?? 'exception');
        } finally {
            $this->tenants->forget();
        }
    }

    /**
     * One recipient outside the platform: the budget follow-up of the projects bound to it, on its cadence. Tenant-checked again
     * here — a project that moved to another workspace, or was archived, is not reported, whatever the pivot says.
     */
    public function sendExternal(ExternalDigestRecipient $recipient, Carbon $localNow, string $locale = 'ar'): string
    {
        $tenantId = (string) $recipient->tenant_id;
        [$from, $to, $key] = self::window($recipient->cadence, $localNow);

        if (! $recipient->active) {
            return 'inactive';
        }
        if (! $this->claimExternal($recipient, $key)) {
            return 'already_sent';
        }
        if ($this->gate->refusal($tenantId, $recipient->email, MailUnsubscribes::EXTERNAL, charge: false) !== null) {
            return $this->finishExternal($recipient, $key, MailSendGate::UNSUBSCRIBED, MailSendGate::UNSUBSCRIBED);
        }

        try {
            $this->tenants->setTenantId($tenantId);
            $projectIds = Project::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->whereIn('id', $recipient->projectIds())
                ->where('status', '!=', 'archived')->whereNull('deleted_at')
                ->pluck('id')->map(static fn ($id): string => (string) $id)->all();
            if ($projectIds === []) {
                return $this->finishExternal($recipient, $key, 'skipped', 'no_projects');
            }
            if (! $this->providers->isConfigured('email')) {
                return $this->finishExternal($recipient, $key, 'awaiting_credentials', 'no_email_provider');
            }
            if (($refusal = $this->gate->refusal($tenantId, $recipient->email, MailUnsubscribes::EXTERNAL, reserve: (int) config('mail.alert_reserve_per_tenant', 200))) !== null) {
                return $this->finishExternal($recipient, $key, $refusal, $refusal);
            }

            $data = $this->summary->build($tenantId, $projectIds, $from, $to, $localNow->copy()->startOfDay());
            $mail = (new SpendSummaryMail($data, $locale, $recipient->cadence, external: true, recipientName: (string) ($recipient->name ?? '')))
                ->offerUnsubscribe($this->unsubscribes->url($tenantId, $recipient->email, MailUnsubscribes::EXTERNAL));
            $sent = Mail::to($recipient->email)->send($mail);

            $this->audit->log(
                action: 'notifications.external_budget.sent',
                entityType: ExternalDigestRecipient::class,
                entityId: (string) $recipient->id,
                after: ['recipient' => MailAddress::mask($recipient->email), 'period' => $key, 'cadence' => $recipient->cadence, 'projects' => $projectIds],
                tenantId: $tenantId,
            );

            return $this->finishExternal($recipient, $key, 'sent', null, TransactionalMailer::messageId($sent), count($projectIds));
        } catch (Throwable $e) {
            return $this->finishExternal($recipient, $key, 'failed', MailFailure::from($e)['code'] ?? 'exception');
        } finally {
            $this->tenants->forget();
        }
    }

    /** Whether this rhythm is due today, in the reader's calendar: daily always, weekly on Sunday, monthly on the 1st. */
    public static function due(string $cadence, Carbon $localNow): bool
    {
        return match ($cadence) {
            'daily' => true,
            'monthly' => $localNow->day === 1,
            default => $localNow->dayOfWeekIso === DigestSchedule::DEFAULT_WEEKDAY,
        };
    }

    private function claimInternal(string $tenantId, User $user, string $key, Carbon $from, Carbon $to): bool
    {
        // `insertOrIgnore`: a losing race (or a second run) is the unique index doing its job, never an error that aborts a transaction.
        return DB::table('digest_sends')->insertOrIgnore([
            'id' => (string) Uuid::uuid4(), 'tenant_id' => $tenantId, 'user_id' => $user->getKey(), 'kind' => self::SWITCH,
            'period_key' => $key, 'status' => 'claimed', 'attempts' => 1, 'attempted_at' => Carbon::now(),
            'window_from' => $from, 'window_to' => $to, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]) === 1;
    }

    private function finishInternal(User $user, string $key, string $status, ?string $reason, ?string $messageId = null): string
    {
        DB::table('digest_sends')->where('user_id', $user->getKey())->where('kind', self::SWITCH)->where('period_key', $key)->update([
            'status' => $status, 'reason' => $reason, 'sent_at' => $status === 'sent' ? Carbon::now() : null,
            'accepted_at' => $status === 'sent' ? Carbon::now() : null, 'provider_message_id' => $messageId, 'updated_at' => Carbon::now(),
        ]);

        return $status;
    }

    private function claimExternal(ExternalDigestRecipient $recipient, string $key): bool
    {
        return DB::table('external_digest_sends')->insertOrIgnore([
            'id' => (string) Uuid::uuid4(), 'tenant_id' => $recipient->tenant_id, 'recipient_id' => $recipient->id,
            'period_key' => $key, 'status' => 'claimed', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]) === 1;
    }

    private function finishExternal(ExternalDigestRecipient $recipient, string $key, string $status, ?string $reason, ?string $messageId = null, int $projects = 0): string
    {
        DB::table('external_digest_sends')->where('recipient_id', $recipient->id)->where('period_key', $key)->update([
            'status' => $status, 'reason' => $reason, 'projects' => $projects, 'sent_at' => $status === 'sent' ? Carbon::now() : null,
            'provider_message_id' => $messageId, 'updated_at' => Carbon::now(),
        ]);

        return $status;
    }
}
