<?php

declare(strict_types=1);

namespace App\Domains\Delivery\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Billing\Models\Invoice;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\ClientWorkspaces\Services\ClientAccess;
use App\Domains\Delivery\Models\DeliveryPack;
use App\Domains\Delivery\Models\PlatformEvidence;
use App\Domains\Delivery\Services\DeliveryOverview;
use App\Domains\Delivery\Services\DeliveryPackService;
use App\Domains\Delivery\Services\DeliverySettings;
use App\Domains\Delivery\Services\PackNotes;
use App\Domains\Delivery\Services\PlatformEvidenceService;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportAnnotation;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * WD-PACK-001, WD-DRAFTS-001, WD-EVIDENCE-001 — the agency's side of the weekly delivery.
 *
 * Every route resolves the client through `ClientAccess`, so an operator narrowed to some clients cannot
 * prepare, approve or read another client's pack by guessing an id.
 */
final class DeliveryController extends Controller
{
    public function __construct(
        private readonly ClientAccess $access,
        private readonly DeliveryPackService $packs,
        private readonly PlatformEvidenceService $evidence,
    ) {}

    /** GET /delivery/packs */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.view'), 403);

        $query = DeliveryPack::query()->latest('period_start')->limit(100);
        if ($request->filled('client')) {
            $query->where('client_workspace_id', (string) $request->query('client'));
        }
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        $rows = $query->get()->filter(fn (DeliveryPack $p): bool => $this->reachable($request, $p))->values();
        $names = ClientWorkspace::query()->whereIn('id', $rows->pluck('client_workspace_id')->unique()->all())->pluck('name', 'id');

        return ApiResponse::success($rows->map(fn (DeliveryPack $p): array => $this->shape($p) + ['client' => $names[$p->client_workspace_id] ?? null])->all(), 'Delivery packs.');
    }

    /**
     * GET /delivery/overview — DELIV-DASH-001, one row per client the operator can reach.
     *
     * Invoice facts appear only for a reader who may see billing for that client; the rest of the row
     * (the report side) is the weekly delivery's own, gated by reports.view.
     */
    public function overview(Request $request, DeliveryOverview $overview): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.view'), 403);

        $rows = ClientWorkspace::query()->where('status', 'active')->whereNull('archived_at')->orderBy('name')->get()
            ->filter(function (ClientWorkspace $c) use ($request): bool {
                try {
                    $this->access->assert($request->user(), 'reports.view', $c);

                    return true;
                } catch (\Throwable) {
                    return false;
                }
            })
            ->map(function (ClientWorkspace $c) use ($request, $overview): array {
                try {
                    $this->access->assert($request->user(), 'billing.view', $c);
                    $billing = true;
                } catch (\Throwable) {
                    $billing = false;
                }

                return $overview->row($c, null, $billing);
            })
            ->values()->all();

        return ApiResponse::success($rows, 'Delivery overview.');
    }

    /** GET /delivery/settings?client= — how this client's periodic delivery is prepared. */
    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate(['client' => ['required', 'uuid']]);
        $workspace = $this->authorise($request, $data['client'], 'reports.view');

        return ApiResponse::success($this->settingsShape($workspace), 'Delivery settings.');
    }

    /** PUT /delivery/settings — DELIVERY-SETTINGS-001. Only the keys sent change; nothing already prepared is touched. */
    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_workspace_id' => ['required', 'uuid'],
            'enabled' => ['sometimes', 'boolean'],
            'cadence' => ['sometimes', 'in:'.implode(',', DeliverySettings::CADENCES)],
            'week_start' => ['sometimes', 'in:'.implode(',', array_keys(DeliverySettings::WEEK_STARTS))],
            'mode' => ['sometimes', 'in:'.implode(',', DeliverySettings::MODES)],
            'components' => ['sometimes', 'array'],
            'components.*' => ['boolean'],
        ]);
        $workspace = $this->authorise($request, $data['client_workspace_id'], 'reports.approve');
        $before = DeliverySettings::for($workspace);
        DeliverySettings::save($workspace, $data);
        $after = DeliverySettings::for($workspace->refresh());
        app(AuditLogger::class)->log('delivery_settings.updated', 'client_workspace', (string) $workspace->getKey(), $before, $after);

        return ApiResponse::success($this->settingsShape($workspace), 'Delivery settings saved.');
    }

    /** @return array<string,mixed> */
    private function settingsShape(ClientWorkspace $workspace): array
    {
        $settings = DeliverySettings::for($workspace);
        [$from, $to] = DeliverySettings::period($settings);

        return $settings + ['next_period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()]];
    }

    /** POST /delivery/packs — prepare the previous week for a project. */
    public function prepare(Request $request): JsonResponse
    {
        $data = $request->validate(['project_id' => ['required', 'uuid'], 'anchor' => ['nullable', 'date']]);
        $project = Project::query()->findOrFail($data['project_id']);
        $this->authorise($request, (string) $project->client_workspace_id, 'reports.approve');

        $pack = $this->packs->prepare($project, isset($data['anchor']) ? Carbon::parse($data['anchor']) : null, $request->user());

        return ApiResponse::success($this->shape($pack), 'Pack prepared.', status: 201);
    }

    /** GET /delivery/packs/{pack} */
    public function show(Request $request, DeliveryPack $pack): JsonResponse
    {
        $this->authorise($request, (string) $pack->client_workspace_id, 'reports.view');

        return ApiResponse::success($this->shape($pack) + [
            'recommendations' => ReportAnnotation::withoutGlobalScopes()->where('report_id', $pack->report_id)->where('type', 'recommendation')->orderByDesc('priority')
                ->get(['id', 'annotation_id', 'platform', 'kpi', 'text_ar', 'text_en', 'proposed_action', 'priority', 'status', 'source', 'version'])->all(),
            'notes' => array_map(fn ($n) => $this->noteShape($n), app(PackNotes::class)->list($pack)),
            'evidence' => PlatformEvidence::query()->where('client_workspace_id', $pack->client_workspace_id)
                ->whereDate('period_start', '<=', $pack->period_end)->whereDate('period_end', '>=', $pack->period_start)
                ->get(['id', 'provider', 'period_start', 'period_end', 'original_name', 'mime', 'size', 'captured_at', 'note', 'include_in_report'])->all(),
        ], 'Delivery pack.');
    }

    /** POST /delivery/packs/{pack}/notes — DELIVERY-NOTES-001, the Owner's own note. */
    public function addNote(Request $request, DeliveryPack $pack, PackNotes $notes): JsonResponse
    {
        $this->authorise($request, (string) $pack->client_workspace_id, 'reports.approve');
        $data = $request->validate([
            'kind' => ['required', 'in:'.implode(',', array_keys(PackNotes::KINDS))],
            'text_ar' => ['nullable', 'string', 'max:600'], 'text_en' => ['nullable', 'string', 'max:600'],
            'audience' => ['nullable', 'in:'.implode(',', PackNotes::AUDIENCES)],
        ]);

        return $this->guard(fn () => $this->noteShape($notes->add($pack, $data['kind'], $data['text_ar'] ?? null, $data['text_en'] ?? null, $data['audience'] ?? 'client', $request->user())));
    }

    /** PATCH /delivery/packs/{pack}/notes/{note} */
    public function updateNote(Request $request, DeliveryPack $pack, string $note, PackNotes $notes): JsonResponse
    {
        $this->authorise($request, (string) $pack->client_workspace_id, 'reports.approve');
        $data = $request->validate([
            'kind' => ['sometimes', 'in:'.implode(',', array_keys(PackNotes::KINDS))],
            'text_ar' => ['sometimes', 'nullable', 'string', 'max:600'], 'text_en' => ['sometimes', 'nullable', 'string', 'max:600'],
            'audience' => ['sometimes', 'in:'.implode(',', PackNotes::AUDIENCES)],
        ]);
        $model = ReportAnnotation::withoutGlobalScopes()->where('report_id', $pack->report_id)->where('id', $note)->firstOrFail();

        return $this->guard(fn () => $this->noteShape($notes->update($pack, $model, $data)));
    }

    /** DELETE /delivery/packs/{pack}/notes/{note} */
    public function deleteNote(Request $request, DeliveryPack $pack, string $note, PackNotes $notes): JsonResponse
    {
        $this->authorise($request, (string) $pack->client_workspace_id, 'reports.approve');
        $model = ReportAnnotation::withoutGlobalScopes()->where('report_id', $pack->report_id)->where('id', $note)->firstOrFail();

        return $this->guard(function () use ($notes, $pack, $model) {
            $notes->delete($pack, $model);

            return null;
        });
    }

    /** PUT /delivery/packs/{pack}/notes/order */
    public function reorderNotes(Request $request, DeliveryPack $pack, PackNotes $notes): JsonResponse
    {
        $this->authorise($request, (string) $pack->client_workspace_id, 'reports.approve');
        $data = $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['string']]);

        return $this->guard(function () use ($notes, $pack, $data) {
            $notes->reorder($pack, $data['ids']);

            return array_map(fn ($n) => $this->noteShape($n), $notes->list($pack));
        });
    }

    /** @return array<string,mixed> */
    private function noteShape(ReportAnnotation $n): array
    {
        return ['id' => $n->id, 'kind' => (string) ($n->evidence['kind'] ?? 'observation'), 'type' => $n->type, 'text_ar' => $n->text_ar, 'text_en' => $n->text_en, 'audience' => $n->audience, 'position' => $n->position, 'status' => $n->status, 'version' => $n->version];
    }

    /** PUT /delivery/packs/{pack}/components */
    public function components(Request $request, DeliveryPack $pack): JsonResponse
    {
        $this->authorise($request, (string) $pack->client_workspace_id, 'reports.approve');
        $data = $request->validate(['components' => ['required', 'array']]);

        return $this->guard(fn () => $this->shape($this->packs->setComponents($pack, $data['components'])));
    }

    /** POST /delivery/packs/{pack}/invoice */
    public function invoice(Request $request, DeliveryPack $pack): JsonResponse
    {
        $this->authorise($request, (string) $pack->client_workspace_id, 'reports.approve');
        $this->authorise($request, (string) $pack->client_workspace_id, 'billing.view');
        $invoice = Invoice::query()->findOrFail($request->validate(['invoice_id' => ['required', 'integer']])['invoice_id']);

        return $this->guard(fn () => $this->shape($this->packs->attachInvoice($pack, $invoice)));
    }

    /** POST /delivery/packs/{pack}/approve */
    public function approve(Request $request, DeliveryPack $pack): JsonResponse
    {
        $this->authorise($request, (string) $pack->client_workspace_id, 'reports.approve');

        return $this->guard(fn () => $this->shape($this->packs->approve($pack, $request->user())));
    }

    /** POST /delivery/packs/{pack}/sent */
    public function sent(Request $request, DeliveryPack $pack): JsonResponse
    {
        $this->authorise($request, (string) $pack->client_workspace_id, 'reports.approve');

        return $this->guard(fn () => $this->shape($this->packs->markSent($pack)));
    }

    /** GET /delivery/packs/{pack}/whatsapp?lang=ar|en&link= */
    public function whatsapp(Request $request, DeliveryPack $pack): JsonResponse
    {
        $this->authorise($request, (string) $pack->client_workspace_id, 'reports.view');
        $link = $request->filled('link') ? (string) $request->query('link') : null;

        return $this->guard(fn () => ['text' => $this->packs->whatsappSummary($pack, (string) $request->query('lang', 'ar'), $link)]);
    }

    /**
     * PATCH /delivery/packs/{pack}/recommendations/{annotation} — the Owner edits a draft in their own words.
     *
     * An edited note is marked `source = edited` so a regeneration never overwrites it, and it drops back to
     * «reviewed» if it had been approved: approval belongs to the words as they were read.
     */
    public function editRecommendation(Request $request, DeliveryPack $pack, string $annotation): JsonResponse
    {
        $this->authorise($request, (string) $pack->client_workspace_id, 'reports.approve');
        abort_if($pack->status === 'sent', 409, 'This pack has already been sent.');

        $data = $request->validate(['text_ar' => ['nullable', 'string', 'max:600'], 'text_en' => ['nullable', 'string', 'max:600'], 'proposed_action' => ['nullable', 'string', 'max:1200']]);
        $ann = ReportAnnotation::withoutGlobalScopes()->where('report_id', $pack->report_id)->where('type', 'recommendation')->where('id', $annotation)->firstOrFail();

        $ann->forceFill(array_filter($data, static fn ($v) => $v !== null) + [
            'source' => 'edited',
            'version' => $ann->version + 1,
            'status' => $ann->status === 'approved' ? 'reviewed' : $ann->status,
            'approved_by' => null,
            'approved_at' => null,
        ])->save();

        // The snapshot is regenerated so the client-facing copy reflects the decision, exactly as an approval does.
        $report = Report::withoutGlobalScopes()->find($pack->report_id);
        if ($report !== null) {
            $report->update(['status' => 'processing']);
            GenerateReportJob::dispatch((string) $report->id);
        }

        return ApiResponse::success($ann->fresh(), 'Recommendation updated.');
    }

    /** POST /delivery/evidence (multipart) */
    public function storeEvidence(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_workspace_id' => ['required', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'provider' => ['required', 'string', 'max:40'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'file' => ['required', 'file', 'max:'.PlatformEvidenceService::MAX_KB, 'mimetypes:'.implode(',', PlatformEvidenceService::MIMES)],
            'note' => ['nullable', 'string', 'max:500'],
            'captured_at' => ['nullable', 'date'],
            'include_in_report' => ['nullable', 'boolean'],
        ]);
        $workspace = $this->authorise($request, $data['client_workspace_id'], 'reports.approve');

        $evidence = $this->evidence->store(
            (string) $workspace->tenant_id, (string) $workspace->getKey(), $data['project_id'] ?? null, $data['provider'],
            Carbon::parse($data['period_start']), Carbon::parse($data['period_end']), $request->file('file'),
            $data['note'] ?? null, isset($data['captured_at']) ? Carbon::parse($data['captured_at']) : null, $request->user(),
            (bool) ($data['include_in_report'] ?? true),
        );

        return ApiResponse::success($evidence->only(['id', 'provider', 'period_start', 'period_end', 'original_name', 'mime', 'size', 'captured_at', 'note', 'include_in_report']), 'Evidence attached.', status: 201);
    }

    /** DELETE /delivery/evidence/{evidence} */
    public function deleteEvidence(Request $request, PlatformEvidence $evidence): JsonResponse
    {
        $this->authorise($request, (string) $evidence->client_workspace_id, 'reports.approve');
        $this->evidence->delete($evidence);

        return ApiResponse::success(null, 'Evidence removed.');
    }

    /** GET /delivery/evidence/{evidence}/file */
    public function evidenceFile(Request $request, PlatformEvidence $evidence): StreamedResponse
    {
        $this->authorise($request, (string) $evidence->client_workspace_id, 'reports.view');

        return Storage::disk($evidence->disk)->response($evidence->path, $evidence->original_name, ['Content-Type' => $evidence->mime, 'X-Content-Type-Options' => 'nosniff']);
    }

    private function authorise(Request $request, string $clientId, string $permission): ClientWorkspace
    {
        $workspace = $this->access->resolve($clientId);
        $this->access->assert($request->user(), $permission, $workspace);

        return $workspace;
    }

    private function reachable(Request $request, DeliveryPack $pack): bool
    {
        try {
            $this->authorise($request, (string) $pack->client_workspace_id, 'reports.view');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param  callable(): mixed  $work */
    private function guard(callable $work): JsonResponse
    {
        try {
            return ApiResponse::success($work(), 'OK.');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), status: 409);
        }
    }

    /** @return array<string,mixed> */
    private function shape(DeliveryPack $pack): array
    {
        return [
            'id' => $pack->id, 'client_workspace_id' => $pack->client_workspace_id, 'project_id' => $pack->project_id,
            'report_id' => $pack->report_id, 'invoice_id' => $pack->invoice_id,
            'period' => ['from' => $pack->period_start->toDateString(), 'to' => $pack->period_end->toDateString()],
            'status' => $pack->status, 'components' => $pack->components,
            'approved_at' => $pack->approved_at?->toIso8601String(), 'sent_at' => $pack->sent_at?->toIso8601String(),
            'readiness' => $this->packs->readiness($pack),
        ];
    }
}
