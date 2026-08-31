<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Campaigns\Models\CampaignAnnotation;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Campaign notes & recommendations (CMC-11). Project + tenant scoped (global scopes) with a
 * fail-closed campaign lookup. Status transitions are audited. Approving/reviewing is gated by the
 * reports.approve permission — the same gate that governs what a client can ever see.
 */
final class CampaignAnnotationController extends Controller
{
    private const KINDS = ['note', 'recommendation'];

    private const STATUSES = ['draft', 'reviewed', 'approved', 'hidden', 'rejected'];

    public function index(Request $request, string $project, string $campaign): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);
        $model = $this->campaign($campaign);

        $rows = CampaignAnnotation::query()
            ->where('campaign_id', $model->id)
            ->when($request->string('kind')->toString(), fn ($q, $k) => $q->where('kind', $k))
            ->latest()
            ->get()
            ->map(fn (CampaignAnnotation $a) => $this->shape($a))
            ->all();

        return ApiResponse::success($rows, 'Campaign annotations.');
    }

    /**
     * RECOMMENDATIONS-001 — every recommendation in the project, across its campaigns.
     *
     * `index()` above answers for ONE campaign, which is the right shape for the campaign page and
     * the wrong shape for the question people actually ask: «what should we act on». There was no
     * screen for that at all — `/app/recommendations` answered 404 — while the records existed,
     * carried a priority, an assignee and a due date, and were readable only by opening campaigns
     * one at a time.
     *
     * This SURFACES stored annotations. It generates nothing: a recommendation here was written by
     * somebody and carries their evidence, and a screen that invented advice from the same figures
     * would be indistinguishable from one that reported it.
     *
     * The campaign's name is joined because a recommendation read outside its campaign page has lost
     * the one piece of context that makes it actionable.
     */
    public function projectIndex(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);

        $rows = CampaignAnnotation::query()
            ->leftJoin('unified_campaigns', 'unified_campaigns.id', '=', 'campaign_annotations.campaign_id')
            ->when(
                $request->string('kind')->toString(),
                fn ($q, $k) => $q->where('campaign_annotations.kind', $k),
                fn ($q) => $q->where('campaign_annotations.kind', 'recommendation'),
            )
            ->when($request->string('status')->toString(), fn ($q, $st) => $q->where('campaign_annotations.status', $st))
            ->when($request->string('priority')->toString(), fn ($q, $pr) => $q->where('campaign_annotations.priority', $pr))
            ->select('campaign_annotations.*', 'unified_campaigns.name as campaign_name')
            ->orderByRaw("CASE campaign_annotations.priority
                WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->latest('campaign_annotations.created_at')
            ->limit(200)
            ->get()
            ->map(fn (CampaignAnnotation $a) => $this->shape($a) + [
                'campaign_id' => $a->campaign_id,
                'campaign_name' => $a->getAttribute('campaign_name'),
            ])
            ->all();

        return ApiResponse::success($rows, 'Project recommendations.');
    }

    public function store(Request $request, string $project, string $campaign, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.update'), 403);
        $model = $this->campaign($campaign);

        $data = $request->validate([
            'kind' => ['required', Rule::in(self::KINDS)],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['nullable', 'string'],
            'platform' => ['nullable', 'string', 'max:60'],
            'kpi' => ['nullable', 'string', 'max:60'],
            'evidence' => ['nullable', 'string'],
            'priority' => ['sometimes', Rule::in(['critical', 'high', 'medium', 'low'])],
            'proposed_action' => ['nullable', 'string'],
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'due_date' => ['nullable', 'date'],
        ]);

        $annotation = CampaignAnnotation::create($data + [
            'campaign_id' => $model->id,
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);
        $audit->log(action: 'campaign.annotation_created', entityType: UnifiedCampaign::class, entityId: (string) $model->id, after: ['kind' => $annotation->kind, 'title' => $annotation->title]);

        return ApiResponse::success($this->shape($annotation), 'Annotation created.', status: 201);
    }

    public function update(Request $request, string $project, string $campaign, string $annotation, AuditLogger $audit): JsonResponse
    {
        $model = $this->campaign($campaign);
        $note = CampaignAnnotation::query()->where('campaign_id', $model->id)->findOrFail($annotation);

        // A status change (review/approve/reject/hide) requires the approval permission; content edits
        // require update. Approving is what can expose a recommendation to a client, so it's gated.
        if ($request->has('status')) {
            abort_unless($request->user()?->hasPermission('reports.approve'), 403);
            $status = $request->validate(['status' => ['required', Rule::in(self::STATUSES)]])['status'];
            $before = $note->status;
            $note->status = $status;
            if ($status === 'reviewed') {
                $note->reviewed_by = $request->user()->id;
            }
            if ($status === 'approved') {
                $note->approved_by = $request->user()->id;
                $note->approved_at = now();
            }
            $note->save();
            $audit->log(action: 'campaign.annotation_status', entityType: UnifiedCampaign::class, entityId: (string) $model->id, before: ['status' => $before], after: ['status' => $status, 'title' => $note->title]);

            return ApiResponse::success($this->shape($note), 'Annotation status updated.');
        }

        abort_unless($request->user()?->hasPermission('campaigns.update'), 403);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'body' => ['nullable', 'string'],
            'platform' => ['nullable', 'string', 'max:60'],
            'kpi' => ['nullable', 'string', 'max:60'],
            'evidence' => ['nullable', 'string'],
            'priority' => ['sometimes', Rule::in(['critical', 'high', 'medium', 'low'])],
            'proposed_action' => ['nullable', 'string'],
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'due_date' => ['nullable', 'date'],
        ]);
        $note->update($data);

        return ApiResponse::success($this->shape($note), 'Annotation updated.');
    }

    private function campaign(string $campaign): UnifiedCampaign
    {
        return UnifiedCampaign::query()->findOrFail($campaign); // 404 cross-project / unknown
    }

    /** @return array<string,mixed> */
    private function shape(CampaignAnnotation $a): array
    {
        return [
            'id' => $a->id, 'kind' => $a->kind, 'status' => $a->status, 'title' => $a->title, 'body' => $a->body,
            'platform' => $a->platform, 'kpi' => $a->kpi, 'evidence' => $a->evidence, 'priority' => $a->priority,
            'proposed_action' => $a->proposed_action, 'assignee_id' => $a->assignee_id,
            'due_date' => optional($a->due_date)->toDateString(), 'is_demo' => $a->is_demo,
            'approved_at' => optional($a->approved_at)->toIso8601String(), 'created_at' => $a->created_at?->toIso8601String(),
        ];
    }
}
