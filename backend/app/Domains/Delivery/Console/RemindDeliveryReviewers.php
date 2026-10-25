<?php

declare(strict_types=1);

namespace App\Domains\Delivery\Console;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Delivery\Models\DeliveryPack;
use App\Domains\Delivery\Services\DeliverySettings;
use App\Domains\Notifications\Services\MemberNotices;
use App\Domains\Ops\Services\ScheduledRunRows;
use App\Domains\Reports\Models\Report;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * DELIVERY-REMINDERS-001 — nudge the person a delivery is waiting for, once a day per reason, and say honestly what happened.
 *
 * Three reasons, each a fact about the pack: a draft that has waited longer than its client's `reminder_hours` (`review_due`), a
 * report that failed to build (`report_failed`), an approved pack not yet marked sent two days after its period ended (`send_due`).
 * Only the client's chosen reviewer is written to; with none chosen nothing is sent (and nothing is guessed). The message is an
 * internal nudge — never to a client. Its delivery outcome is recorded by the mailer, so without SMTP it is recorded as failed and
 * not as sent; the review TASK on the board (assigned to the reviewer, with a due date) is the in-app reminder and needs no mail.
 */
final class RemindDeliveryReviewers extends Command
{
    protected $signature = 'delivery:remind {--now= : Treat this ISO time as now}';

    protected $description = 'Remind the chosen reviewer of delivery packs that are waiting, failed, or approved but not sent.';

    public function handle(MemberNotices $notices, TenantContext $tenants): int
    {
        $now = $this->option('now') !== null ? Carbon::parse((string) $this->option('now')) : Carbon::now();
        $sent = 0;
        $considered = 0;

        $packs = DeliveryPack::withoutGlobalScopes()->whereIn('status', ['draft', 'approved'])->get();
        foreach ($packs as $pack) {
            $workspace = ClientWorkspace::withoutGlobalScopes()->find($pack->client_workspace_id);
            if ($workspace === null || $workspace->status !== 'active' || $workspace->archived_at !== null) {
                continue;
            }
            $settings = DeliverySettings::for($workspace);
            $reviewer = $settings['reviewer_id'] === null ? null : User::query()->find($settings['reviewer_id']);
            $tenant = Tenant::query()->find($pack->tenant_id);
            if ($reviewer === null || $tenant === null) {
                continue;
            }
            $tenants->setTenantId((string) $pack->tenant_id);

            $reason = $this->reasonFor($pack, $settings['reminder_hours'], $now);
            if ($reason === null) {
                continue;
            }
            $considered++;
            $result = $notices->deliveryReminder(
                $reviewer, $tenant, $reason, (string) $workspace->name,
                $pack->period_start->toDateString().' → '.$pack->period_end->toDateString(), (string) $pack->id,
            );
            if (in_array($result, ['sent', 'sandbox'], true)) {
                $sent++;
            }
            $this->line(sprintf('  %s: %s → %s', $workspace->name, $reason, $result));
        }

        app(ScheduledRunRows::class)->report($considered);
        $this->line(sprintf('  %d reminder(s) due, %d sent (the rest were already sent today, or the email channel is not configured).', $considered, $sent));

        return self::SUCCESS;
    }

    private function reasonFor(DeliveryPack $pack, int $hours, Carbon $now): ?string
    {
        $report = $pack->report_id === null ? null : Report::withoutGlobalScopes()->find($pack->report_id);
        if ($report !== null && $report->status === 'failed') {
            return 'report_failed';
        }
        if ($pack->status === 'draft' && $pack->created_at !== null && $pack->created_at->copy()->addHours($hours)->lte($now)) {
            return 'review_due';
        }
        if ($pack->status === 'approved' && $pack->sent_at === null && $pack->period_end->copy()->addDays(2)->lte($now)) {
            return 'send_due';
        }

        return null;
    }
}
