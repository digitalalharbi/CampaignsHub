<?php

declare(strict_types=1);

namespace App\Domains\Delivery\Services;

use App\Domains\Billing\Models\Invoice;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Delivery\Models\DeliveryPack;
use App\Domains\Projects\Concerns\ProjectScope;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportSchedule;
use App\Domains\Reports\Models\ReportShare;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DELIV-DASH-001 — one row per client: where this week's delivery stands, where the money stands, and the
 * single next thing the Owner should do about it.
 *
 * ## It reads; it decides nothing new
 *
 * The pack's readiness is `DeliveryPackService::readiness()`, the invoice's state is `displayStatus()`, and
 * «viewed» is the report link's own view record. Nothing is stored here, so the dashboard cannot disagree
 * with the screens it summarises.
 *
 * ## The report and the invoice stay independent (RB-INDEPENDENT-001)
 *
 * Neither moves the other. The one place they meet is the follow-up signal: a client who OPENED the report
 * and still has an OVERDUE invoice is a conversation to have, and that is a fact about two separate
 * records, not a state of either.
 *
 * Money is never summed across clients: each row states its own invoice in its own currency.
 */
final class DeliveryOverview
{
    public function __construct(private readonly DeliveryPackService $packs) {}

    /**
     * @param  bool  $mayReadBilling  whether the reader may see invoices — a row without it carries none
     * @param  list<string>|null  $reachableProjects  the reader's project confinement (`ProjectReach::confinedIdsFor`); null = none
     * @return array<string,mixed>
     */
    public function row(ClientWorkspace $client, ?Carbon $today = null, bool $mayReadBilling = true, ?array $reachableProjects = null): array
    {
        $today = ($today ?? Carbon::today())->copy();

        $pack = DeliveryPack::query()->where('client_workspace_id', $client->getKey())->latest('period_start')->first();
        $readiness = $pack === null ? null : $this->packs->readiness($pack);

        $invoice = $mayReadBilling
            ? Invoice::query()->where('client_workspace_id', $client->getKey())
                ->whereIn('status', Invoice::FROZEN_STATUSES)->latest('issued_at')->first()
            : null;
        $invoiceState = $invoice?->displayStatus($today);
        $due = $invoice === null ? null : max(0.0, round((float) $invoice->total - (float) $invoice->amount_paid, 2));

        $viewedAt = $pack?->report_id === null ? null : ReportShare::withoutGlobalScopes()
            ->where('report_id', $pack->report_id)->max('last_viewed_at');
        $viewed = $viewedAt !== null;

        $overdue = $invoiceState === 'overdue';
        $followUp = $viewed && $overdue;

        $scheduled = $this->scheduled($client, $reachableProjects);
        $settings = DeliverySettings::for($client);

        [$action, $visual] = $this->next($pack, $readiness, $overdue, $today, $settings);

        /*
         * CONS-DELIVERY-SCHEDULED — a client whose reports go out on a schedule, and who has no weekly pack switched on, is
         * not waiting for the operator to «prepare» anything: that row used to read «لا توجد حزمة بعد» and ask for a pack
         * while the scheduled report was being sent. The pack flow still wins wherever it is switched on.
         */
        if ($pack === null && $scheduled['active'] > 0 && ($settings['enabled'] ?? false) !== true) {
            [$action, $visual] = $overdue ? ['follow_up_payment', 'urgent'] : ['none', 'ok'];
        }

        return [
            'client_id' => (string) $client->getKey(),
            'client' => $client->name,
            'pack' => $pack === null ? null : [
                'id' => (string) $pack->id,
                'period' => ['from' => $pack->period_start->toDateString(), 'to' => $pack->period_end->toDateString()],
                'status' => $pack->status,
                'sent_at' => $pack->sent_at?->toIso8601String(),
            ],
            'scheduled' => $scheduled,
            'report' => $readiness === null ? null : ['status' => $readiness['report'], 'undecided_recommendations' => $readiness['undecided_recommendations']],
            'evidence' => $readiness === null ? null : $readiness['evidence']['count'],
            'last_viewed_at' => $viewedAt === null ? null : Carbon::parse((string) $viewedAt)->toIso8601String(),
            'invoice' => $invoice === null ? null : [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $invoiceState,
                'currency' => $invoice->currency,
                'total' => (float) $invoice->total,
                'amount_due' => $due,
                'due_date' => $invoice->due_date?->toDateString(),
            ],
            'follow_up_required' => $followUp,
            'next_action' => $followUp ? 'follow_up_payment' : $action,
            'visual' => $followUp ? 'urgent' : $visual,
        ];
    }

    /**
     * CONS-DELIVERY-SCHEDULED — the client's ACTUAL report schedules, read from `report_schedules` and their own ledger.
     *
     * Scoped by the tenant scope on every model, by the client's projects, and by the reader's project confinement. A copy
     * switched off as a duplicate is not a schedule. The last report is the one the schedules' delivery ledger names, or a
     * report the dispatcher marked with its schedule; its delivery states are exactly what the ledger recorded.
     *
     * @param  list<string>|null  $reachableProjects
     * @return array{active:int,next_run_at:?string,last_run_at:?string,last_report:?array<string,mixed>,deliveries:array<string,int>}
     */
    private function scheduled(ClientWorkspace $client, ?array $reachableProjects): array
    {
        $projects = Project::query()->live()->where('client_workspace_id', $client->getKey()) // PROJECT-LIFECYCLE-001
            ->when($reachableProjects !== null, fn ($q) => $q->whereIn('id', $reachableProjects))
            ->pluck('id')->map(fn ($id) => (string) $id)->all();

        $schedules = $projects === [] ? collect() : ReportSchedule::withoutGlobalScope(ProjectScope::class)
            ->whereIn('project_id', $projects)->get();
        $active = $schedules->where('active', true);

        $ids = $schedules->pluck('id')->map(fn ($id) => (string) $id)->all();
        $lastReportId = $ids === [] ? null : DB::table('report_deliveries')->where('tenant_id', $client->tenant_id)
            ->whereIn('schedule_id', $ids)->whereNotNull('report_id')->orderByDesc('created_at')->value('report_id');
        $last = $lastReportId === null ? null : Report::withoutGlobalScope(ProjectScope::class)
            ->whereIn('project_id', $projects)->whereKey($lastReportId)->first();

        $deliveries = $last === null ? [] : DB::table('report_deliveries')->where('tenant_id', $client->tenant_id)
            ->where('report_id', $last->getKey())->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')
            ->pluck('total', 'status')->map(fn ($n) => (int) $n)->all();

        $next = $active->pluck('next_run_at')->filter()->min();
        $lastRun = $schedules->pluck('last_run_at')->filter()->max();

        return [
            'active' => $active->count(),
            'next_run_at' => $next?->toIso8601String(),
            'last_run_at' => $lastRun?->toIso8601String(),
            'last_report' => $last === null ? null : [
                'id' => (string) $last->getKey(),
                'project_id' => (string) $last->project_id,
                'status' => $last->status,
                'generated_at' => $last->generated_at?->toIso8601String(),
                'period' => ['from' => $last->period_start?->toDateString(), 'to' => $last->period_end?->toDateString()],
            ],
            'deliveries' => $deliveries,
        ];
    }

    /**
     * The single next step, and how loudly to say so.
     *
     * @param  array<string,mixed>|null  $readiness
     * @param  array<string,mixed>  $settings  the client's DeliverySettings
     * @return array{0: string, 1: string}
     */
    private function next(?DeliveryPack $pack, ?array $readiness, bool $overdue, Carbon $today, array $settings): array
    {
        $expected = DeliverySettings::period($settings, $today)[0]->toDateString();

        if ($pack === null || $pack->period_start->toDateString() < $expected) {
            return ['prepare_pack', $overdue ? 'urgent' : 'attention'];
        }

        if ($pack->sent_at === null && $pack->approved_at !== null) {
            return ['send_pack', 'attention'];
        }

        if ($pack->approved_at === null) {
            return [($readiness['ready_to_approve'] ?? false) ? 'approve_pack' : 'review_pack', 'attention'];
        }

        return $overdue ? ['follow_up_payment', 'urgent'] : ['none', 'ok'];
    }
}
