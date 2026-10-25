<?php

declare(strict_types=1);

namespace App\Domains\Delivery\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\Billing\Models\Invoice;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Delivery\Models\DeliveryPack;
use App\Domains\Delivery\Models\PlatformEvidence;
use App\Domains\Metrics\Services\ReportingCurrency;
use App\Domains\Metrics\Services\ReportingTimezone;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportAnnotation;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ShareService;
use App\Models\User;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * WD-PACK-001, WD-DRAFTS-001, WD-WHATSAPP-001 — prepare, review and hand over one client's weekly delivery.
 *
 * ## It orchestrates; it computes nothing
 *
 * The figures are the report snapshot's, which is the canonical report engine's. The recommendations are the
 * report's annotations, which are born DRAFT and reach a client only when the Owner approves them. The
 * invoice is the invoice. This class decides which previous week to prepare, which pieces the client gets,
 * and whether the Owner has finished reviewing — and writes the message that accompanies them.
 *
 * ## What the client is never told before the Owner says so
 *
 * The WhatsApp summary is built from APPROVED next steps only and refuses to exist for an unapproved pack.
 * A draft observation is a machine's reading of the week; sending it unreviewed is how a client gets told
 * something the agency has not decided it believes.
 *
 * ## Report and invoice stay independent
 *
 * Sending the pack never moves the invoice, and an invoice being paid never moves the pack
 * (RB-INDEPENDENT-001): the amount due appears in the message as a fact about the invoice, nothing more.
 */
final class DeliveryPackService
{
    /** What a client receives unless the agency says otherwise. Money in the message is opt-in. */
    private const DEFAULTS = ['whatsapp' => true, 'link' => true, 'report_pdf' => true, 'evidence' => true, 'invoice_pdf' => true, 'amount_due' => true, 'spend' => false];

    public function __construct(private readonly AuditLogger $audit, private readonly ShareService $shares) {}

    /** The ISO week (Monday to Sunday) before the one containing $anchor, in the reporting timezone. */
    public static function previousWeek(?Carbon $anchor = null): array
    {
        $day = ($anchor ?? Carbon::now(ReportingTimezone::DEFAULT))->copy()->startOfDay();
        $monday = $day->copy()->startOfWeek(Carbon::MONDAY)->subWeek();

        return [$monday, $monday->copy()->addDays(6)];
    }

    /** Prepare the previous week's pack for a project, and queue its report. Asking again returns the same pack. */
    public function prepare(Project $project, ?Carbon $anchor = null, ?User $by = null): DeliveryPack
    {
        $workspace = ClientWorkspace::query()->findOrFail($project->client_workspace_id);
        $settings = DeliverySettings::for($workspace);
        [$from, $to] = DeliverySettings::period($settings, $anchor);

        $existing = DeliveryPack::query()->where('project_id', $project->getKey())->whereDate('period_start', $from)->first();
        if ($existing !== null) {
            return $existing;
        }

        $report = new Report;
        $report->forceFill([
            'tenant_id' => $project->tenant_id,
            'project_id' => $project->getKey(),
            'name' => $workspace->name.' — '.$from->toDateString().' → '.$to->toDateString(),
            'type' => $settings['cadence'] === 'monthly' ? 'monthly' : 'weekly',
            'audience' => 'client',
            'mode' => $settings['mode'],
            'status' => 'processing',
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'currency' => ReportingCurrency::DEFAULT,
            'config' => ['period_kind' => $settings['cadence'] === 'monthly' ? 'monthly' : 'weekly'],
            'created_by' => $by?->id,
        ])->save();
        GenerateReportJob::dispatch((string) $report->id);

        $pack = DeliveryPack::create([
            'tenant_id' => $project->tenant_id,
            'client_workspace_id' => $workspace->getKey(),
            'project_id' => $project->getKey(),
            'report_id' => $report->id,
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'status' => 'draft',
            'components' => $this->componentsFor($workspace),
            'created_by' => $by?->id,
        ]);
        $this->audit->log('delivery_pack.prepared', 'delivery_pack', (string) $pack->id, null, ['period' => $from->toDateString().' → '.$to->toDateString()]);
        DeliveryTasks::ensureFor($pack);

        return $pack;
    }

    /** @return array<string,bool> the agency's per-client choice laid over the defaults */
    private function componentsFor(ClientWorkspace $workspace): array
    {
        $chosen = (array) data_get($workspace->settings, 'delivery.components', []);

        return $this->sanitise($chosen);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,bool>
     */
    private function sanitise(array $input): array
    {
        $out = self::DEFAULTS;
        foreach (DeliveryPack::COMPONENTS as $key) {
            if (array_key_exists($key, $input)) {
                $out[$key] = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $components */
    public function setComponents(DeliveryPack $pack, array $components): DeliveryPack
    {
        $this->requireOpen($pack);
        $pack->update(['components' => $this->sanitise($components + (array) $pack->components)]);

        // An approved pack's link follows the choices made after approval too: what the link may show is never wider than the pack says.
        if ($pack->approved_at !== null) {
            if ($pack->share_id !== null) {
                ReportShare::withoutGlobalScopes()->whereKey($pack->share_id)
                    ->update(['allow_download' => $pack->includes('report_pdf'), 'hide_spend' => ! $pack->includes('spend')]);
            }
            $this->ensureLink($pack);
        }

        return $pack;
    }

    public function attachInvoice(DeliveryPack $pack, Invoice $invoice): DeliveryPack
    {
        $this->requireOpen($pack);
        if ((string) $invoice->client_workspace_id !== (string) $pack->client_workspace_id) {
            throw new InvalidArgumentException('That invoice belongs to a different client.');
        }
        $pack->update(['invoice_id' => $invoice->getKey()]);

        return $pack;
    }

    /**
     * What stands between this pack and approval — the Owner's checklist, in one place.
     *
     * @return array<string,mixed>
     */
    public function readiness(DeliveryPack $pack): array
    {
        $report = $pack->report_id === null ? null : Report::withoutGlobalScopes()->find($pack->report_id);
        $reportState = $report === null ? 'missing' : (string) $report->status;

        // Recommendations the Owner has not decided on. «reviewed» is looked-at, not approved, so it counts.
        $undecided = $report === null ? 0 : ReportAnnotation::withoutGlobalScopes()->where('report_id', $report->id)
            ->where('type', 'recommendation')->whereIn('status', ['draft', 'reviewed'])->count();
        $approvedNotes = $report === null ? 0 : ReportAnnotation::withoutGlobalScopes()->where('report_id', $report->id)
            ->where('type', 'recommendation')->where('status', 'approved')->count();

        $evidence = PlatformEvidence::query()
            ->where('client_workspace_id', $pack->client_workspace_id)
            ->whereDate('period_start', '<=', $pack->period_end)->whereDate('period_end', '>=', $pack->period_start)->count();

        $invoice = $pack->invoice_id === null ? null : Invoice::withoutGlobalScopes()->find($pack->invoice_id);

        return [
            'report' => $reportState,
            'undecided_recommendations' => $undecided,
            'approved_recommendations' => $approvedNotes,
            'evidence' => ['included' => $pack->includes('evidence'), 'count' => $evidence],
            'invoice' => ['included' => $pack->includes('invoice_pdf') || $pack->includes('amount_due'), 'status' => $invoice?->displayStatus() ?? 'none'],
            'approved' => $pack->approved_at !== null,
            'ready_to_approve' => $reportState === 'completed' && $undecided === 0 && $pack->approved_at === null,
        ];
    }

    public function approve(DeliveryPack $pack, User $by): DeliveryPack
    {
        $this->requireOpen($pack);
        $readiness = $this->readiness($pack);

        if ($readiness['report'] !== 'completed') {
            throw new InvalidArgumentException('The report is not ready yet ('.$readiness['report'].').');
        }
        if ($readiness['undecided_recommendations'] > 0) {
            throw new InvalidArgumentException($readiness['undecided_recommendations'].' recommendation(s) still need a decision: approve, hide or reject each one.');
        }

        $pack->update(['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => Carbon::now()]);
        $this->audit->log('delivery_pack.approved', 'delivery_pack', (string) $pack->id);
        $this->ensureLink($pack, $by);

        return $pack;
    }

    /**
     * DELIVERY-LINK-001 — the one report link this pack sends, minted at approval and reused by every message built from the pack.
     *
     * Only when the client is to receive a link at all. What the link shows follows the pack's own choices: spend stays hidden
     * unless the Owner switched it on, and the PDF download is offered only if the PDF is part of the delivery. Asking again returns
     * the same address while that link is alive; a revoked or expired link is replaced rather than sent dead.
     */
    public function ensureLink(DeliveryPack $pack, ?User $by = null): ?string
    {
        if (! $pack->includes('link') || $pack->report_id === null) {
            return null;
        }
        $current = $this->linkUrl($pack);
        if ($current !== null) {
            return $current;
        }
        $report = Report::withoutGlobalScopes()->find($pack->report_id);
        if ($report === null) {
            return null;
        }

        [$share, $raw] = $this->shares->create($report, [
            'allow_download' => $pack->includes('report_pdf'),
            'hide_spend' => ! $pack->includes('spend'),
        ], $by?->id);
        $pack->forceFill(['share_id' => $share->id, 'share_token' => $raw])->save();
        $this->audit->log('delivery_pack.link_created', 'delivery_pack', (string) $pack->id, null, ['share' => (string) $share->id]);

        return ShareService::urlFor($raw);
    }

    /** The pack's report link, if it has one that is still alive (not revoked, not expired). */
    public function linkUrl(DeliveryPack $pack): ?string
    {
        $raw = $pack->share_token;
        if ($raw === null || $raw === '') {
            return null;
        }

        return $this->shares->resolveActive((string) $raw) === null ? null : ShareService::urlFor((string) $raw);
    }

    /** Record that the pack went out. The invoice is NOT touched — sending a report is not issuing a bill. */
    public function markSent(DeliveryPack $pack): DeliveryPack
    {
        if ($pack->status !== 'approved') {
            throw new InvalidArgumentException('Only an approved pack can be marked as sent.');
        }
        $pack->update(['status' => 'sent', 'sent_at' => Carbon::now()]);
        $this->audit->log('delivery_pack.sent', 'delivery_pack', (string) $pack->id);
        DeliveryTasks::closeFor($pack);

        return $pack;
    }

    /**
     * The message to paste into WhatsApp: the week in a few lines, the approved next steps, and what is due.
     *
     * Built from the report snapshot and the invoice as they stand NOW, and only for an approved pack.
     * Money appears only when the Owner switched `spend` on and the report's spend is whole.
     *
     * @param  string|null  $link  the report's public link, when one exists
     */
    public function whatsappSummary(DeliveryPack $pack, string $lang = 'ar', ?string $link = null): string
    {
        if ($pack->approved_at === null) {
            throw new InvalidArgumentException('The summary is written only after the Owner approves the pack.');
        }

        $ar = $lang !== 'en';
        $report = Report::withoutGlobalScopes()->find($pack->report_id);
        $data = $report === null ? [] : (array) $report->data;
        $totals = (array) ($data['totals'] ?? []);
        $workspace = ClientWorkspace::query()->find($pack->client_workspace_id);
        $period = $pack->period_start->toDateString().' → '.$pack->period_end->toDateString();
        $num = static fn ($v): string => number_format((float) $v, 0, '.', ',');

        $lines = [];
        $lines[] = $ar ? "مرحباً، تقرير أداء حملات {$workspace?->name} عن الفترة {$period}:" : "Hello — {$workspace?->name} campaign performance for {$period}:";

        if (isset($totals['conversions'])) {
            $lines[] = ($ar ? '• النتائج: ' : '• Results: ').$num($totals['conversions']);
        }
        if (isset($totals['impressions'])) {
            $lines[] = ($ar ? '• مرات الظهور: ' : '• Impressions: ').$num($totals['impressions']);
        }
        if ($pack->includes('spend') && isset($totals['spend']) && ((int) ($totals['spend_withheld_rows'] ?? 0)) === 0) {
            $lines[] = ($ar ? '• الإنفاق: ' : '• Spend: ').number_format((float) $totals['spend'], 2, '.', ',').' '.($data['currency'] ?? ReportingCurrency::DEFAULT);
        }

        $steps = ReportAnnotation::withoutGlobalScopes()->where('report_id', $pack->report_id)
            ->where('type', 'recommendation')->where('status', 'approved')->where('audience', '!=', 'internal')->orderByRaw('position is null')->orderBy('position')->orderByDesc('priority')->limit(3)->get();
        if ($steps->isNotEmpty()) {
            $lines[] = $ar ? 'أبرز الخطوات القادمة:' : 'Next steps:';
            foreach ($steps as $step) {
                $lines[] = '– '.($step->text_ar ?: $step->text_en);
            }
        }

        $link ??= $this->linkUrl($pack);
        if ($link !== null && $pack->includes('link')) {
            $lines[] = ($ar ? 'التقرير التفاعلي: ' : 'Interactive report: ').$link;
        }

        $invoice = $pack->invoice_id === null ? null : Invoice::withoutGlobalScopes()->find($pack->invoice_id);
        if ($invoice !== null && $pack->includes('amount_due') && in_array($invoice->status, Invoice::FROZEN_STATUSES, true)) {
            $due = max(0, (float) $invoice->total - (float) $invoice->amount_paid);
            $lines[] = $ar
                ? "الفاتورة {$invoice->number}: المبلغ المستحق ".number_format($due, 2, '.', ',')." {$invoice->currency} — الاستحقاق {$invoice->due_date?->toDateString()}"
                : "Invoice {$invoice->number}: amount due ".number_format($due, 2, '.', ',')." {$invoice->currency} — due {$invoice->due_date?->toDateString()}";
        }

        return implode("\n", $lines);
    }

    private function requireOpen(DeliveryPack $pack): void
    {
        if ($pack->status === 'sent') {
            throw new InvalidArgumentException('This pack has already been sent.');
        }
    }
}
