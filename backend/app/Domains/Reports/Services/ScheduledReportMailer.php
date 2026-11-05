<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Notifications\Mail\ScheduledReportMail;
use App\Domains\Notifications\Services\DigestScope;
use App\Domains\Notifications\Services\TransactionalMailer;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportSchedule;
use App\Domains\Reports\Support\ScheduledReportFigures;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Models\User;
use App\Support\Frontend;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mails the `report_deliveries` rows whose report has finished — EMAIL-GAPS (§17.3.2, §17.4.4).
 *
 * The same five-minute command that creates them runs this, so a report is mailed once it exists, and
 * only `queued` rows are read. No older row is ever picked up: the `awaiting_provider_credentials` rows
 * already on an install are not a backlog that starts sending on deploy.
 *
 * ## Who may receive it — the Owner's question is still open
 *
 * - An internal member is an active app or agency membership in this tenant whose reach includes the
 *   report's project, decided by `DigestScope` at send time.
 * - Anybody else, a client-portal user included, is an OUTSIDE recipient: refused with a recorded reason
 *   unless `reports.schedule_mail.outside_recipients` is on, which only the Owner decides. A row
 *   refused here is never mailed later, even if the setting changes.
 * - A member whose reach does not include this project is refused either way.
 *
 * ## Links
 *
 * A member gets the report inside the product. An outside recipient, when allowed, gets a NEW link minted
 * for this run with spend and campaign names hidden; existing links are not read or changed. A link
 * opens a report without an account, so that message is sent directly and never put on a queue.
 *
 * One email per recipient and report, whatever `formats` says: the rows for that recipient share one
 * outcome. Nothing is attached; the files stay behind the button.
 */
final class ScheduledReportMailer
{
    /** A report still `processing` after this long is not coming, and its deliveries must say so. */
    private const GIVE_UP_AFTER_HOURS = 24;

    public function __construct(
        private readonly TransactionalMailer $mailer,
        private readonly DigestScope $scope,
        private readonly ClientReportView $clientView,
        private readonly ShareService $shares,
        private readonly TenantContext $tenants,
    ) {}

    /** @return int recipients handled */
    public function sendReady(): int
    {
        $handled = 0;

        $rows = DB::table('report_deliveries')->where('status', 'queued')->where('channel', 'email')
            // A row with a key has been handed to the mail ledger, which writes its outcome back.
            ->whereNotNull('report_id')->whereNull('mail_dedup_key')->orderBy('created_at')->limit(500)->get();

        foreach ($rows->groupBy('report_id') as $reportId => $forReport) {
            $report = Report::withoutGlobalScopes()->find($reportId);

            if ($report === null || $report->status === 'failed' || $this->tooLate($report)) {
                $this->mark($forReport, 'failed', 'report_failed');

                continue;
            }

            if ($report->status !== 'completed') {
                continue;
            }

            $this->tenants->setTenantId((string) $report->tenant_id);

            try {
                foreach ($forReport->groupBy(static fn (object $row): string => Str::lower((string) $row->recipient)) as $email => $forRecipient) {
                    try {
                        $this->sendOne($report, (string) $email, $forRecipient);
                    } catch (Throwable $e) {
                        // One recipient's message must not stop the others, and must not stay `queued`.
                        report($e);
                        $this->mark($forRecipient, 'failed', 'could_not_compose');
                    }
                    $handled++;
                }
            } finally {
                $this->tenants->forget();
            }
        }

        return $handled;
    }

    /** @param  Collection<int, \stdClass>  $rows */
    private function sendOne(Report $report, string $email, Collection $rows): void
    {
        $schedule = ReportSchedule::withoutGlobalScopes()->find($rows->first()->schedule_id);
        $tenantId = (string) $report->tenant_id;
        $member = $this->memberOf($tenantId, $email);
        $inside = $member !== null && in_array((string) $report->project_id, $this->scope->projectIdsFor($member, $tenantId), true);

        if (! $inside) {
            $reason = $this->outsideRefusal($member, $report);

            if ($reason !== null) {
                $this->mark($rows, 'suppressed', $reason);

                return;
            }
        }

        $key = 'report-mail:'.$report->id.':'.substr(hash('sha256', $email), 0, 16);
        DB::table('report_deliveries')->whereIn('id', $rows->pluck('id'))->update(['mail_dedup_key' => $key, 'updated_at' => Carbon::now()]);

        $locale = ($schedule->language ?? 'ar') === 'en' ? 'en' : 'ar';
        $mail = $this->compose($report, (string) ($rows->first()->subject ?? $report->name), $locale, $this->nameFor($member, $schedule, $email), $inside ? null : $this->outsideLink($report));
        $arguments = ['recipient' => $email, 'mail' => $mail, 'kind' => 'scheduled_report', 'template' => 'mail.scheduled-report', 'locale' => $locale, 'tenantId' => $tenantId, 'userId' => $member === null ? null : (int) $member->getKey(), 'dedupKey' => $key];

        $inside ? $this->mailer->queue(...$arguments) : $this->mailer->send(...$arguments);
    }

    private function compose(Report $report, string $title, string $locale, string $name, ?string $link): ScheduledReportMail
    {
        $ar = $locale === 'ar';
        $figures = ScheduledReportFigures::from($this->clientView->filter((array) ($report->data ?? [])), $ar);
        $project = Project::withoutGlobalScopes()->find($report->project_id);

        return new ScheduledReportMail(
            reportTitle: $title,
            context: (string) ($project->name ?? $report->name),
            period: $report->period_start?->toDateString().' → '.$report->period_end?->toDateString(),
            cards: $figures['cards'],
            bestContent: $figures['best'],
            improveContent: $figures['improve'],
            url: $link ?? Frontend::url('/app/reports'),
            lang: $locale,
            recipientName: $name,
        );
    }

    /** @return string|null why this outside recipient is refused; null when the Owner has allowed them */
    private function outsideRefusal(?User $member, Report $report): ?string
    {
        if ($member !== null) {
            return 'outside_project_access';
        }

        if (config('reports.schedule_mail.outside_recipients') !== true) {
            return 'outside_recipient_off';
        }

        return ($report->audience ?? 'client') === 'internal' ? 'internal_audience' : null;
    }

    private function outsideLink(Report $report): string
    {
        [, $raw] = $this->shares->create($report, [
            'hide_spend' => true,
            'hide_campaign_names' => true,
            'expires_at' => Carbon::now()->addDays(90),
        ], $report->created_by === null ? null : (int) $report->created_by);

        return Frontend::url("/r/{$raw}");
    }

    /** The internal member behind this address: app or agency, active, in THIS tenant. */
    private function memberOf(string $tenantId, string $email): ?User
    {
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user === null) {
            return null;
        }

        $isMember = Membership::query()->where('tenant_id', $tenantId)->where('user_id', $user->getKey())
            ->where('status', 'active')->whereIn('portal', [Portal::App->value, Portal::Agency->value])->exists();

        return $isMember ? $user : null;
    }

    private function nameFor(?User $member, ?ReportSchedule $schedule, string $email): string
    {
        if ($member !== null) {
            return (string) $member->name;
        }

        foreach ((array) ($schedule->recipients ?? []) as $recipient) {
            if (is_array($recipient) && Str::lower((string) ($recipient['email'] ?? '')) === $email) {
                return (string) ($recipient['name'] ?? '');
            }
        }

        return '';
    }

    private function tooLate(Report $report): bool
    {
        return $report->status !== 'completed' && $report->created_at !== null
            && $report->created_at->lt(Carbon::now()->subHours(self::GIVE_UP_AFTER_HOURS));
    }

    /** @param  Collection<int, \stdClass>  $rows */
    private function mark(Collection $rows, string $status, string $reason): void
    {
        DB::table('report_deliveries')->whereIn('id', $rows->pluck('id'))->update(['status' => $status, 'reason' => $reason, 'updated_at' => Carbon::now()]);
    }
}
