<?php

declare(strict_types=1);

namespace App\Domains\CRM\Http\Controllers;

use App\Domains\CRM\Access\LeadVisibility;
use App\Domains\CRM\Models\Lead;
use App\Domains\CRM\Models\Opportunity;
use App\Domains\CRM\Models\PipelineStage;
use App\Domains\CRM\Resources\OpportunityResource;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Access\ProjectReach;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OpportunityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('leads.view'), 403);

        $user = $request->user();
        $reach = app(ProjectReach::class);

        // SEC-1 — an opportunity belongs to the project of its lead. The list holds only those whose lead
        // this reader may see; an opportunity with no lead belongs to no client, so only a reader with no
        // client / project ceiling keeps it.
        $visibleLeads = app(LeadVisibility::class)->scopeForReader(Lead::query(), $user, null)->select('leads.id');
        $opportunities = Opportunity::query()
            ->with(['stage', 'lead'])
            ->where(static function ($q) use ($visibleLeads, $reach): void {
                $q->whereIn('lead_id', $visibleLeads);
                if ($reach->ceilingProjectIds() === null) {
                    $q->orWhereNull('lead_id');
                }
            })
            ->latest()
            ->get();

        return ApiResponse::success(
            OpportunityResource::collection($opportunities),
            'Opportunities retrieved.',
        );
    }

    public function show(Request $request, Opportunity $opportunity): JsonResponse
    {
        abort_unless($request->user()->hasPermission('leads.view'), 403);
        $this->assertMayWork($request, $opportunity, ProjectCapability::LEADS_VIEW);
        $opportunity->load(['stage', 'lead']);

        return ApiResponse::success(new OpportunityResource($opportunity), 'Opportunity retrieved.');
    }

    /** Move an opportunity to another stage of its pipeline. */
    public function updateStage(Request $request, Opportunity $opportunity): JsonResponse
    {
        abort_unless($request->user()->hasPermission('leads.update'), 403);
        $this->assertMayWork($request, $opportunity, ProjectCapability::LEADS_UPDATE);

        $validated = $request->validate([
            'stage_id' => ['required', 'uuid'],
        ]);

        /** @var PipelineStage|null $stage */
        $stage = PipelineStage::where('pipeline_id', $opportunity->pipeline_id)
            ->find($validated['stage_id']);
        abort_if($stage === null, 422, 'Stage does not belong to this pipeline.');

        $opportunity->update([
            'stage_id' => $stage->id,
            'probability' => $stage->probability,
            'status' => $stage->is_won ? 'won' : ($stage->is_lost ? 'lost' : 'open'),
        ]);

        return ApiResponse::success(new OpportunityResource($opportunity->load(['stage', 'lead'])), 'Stage updated.');
    }

    /**
     * SEC-1 — the capability on the project of the opportunity's LEAD (never a request parameter), and
     * the lead's own visibility. An opportunity with no lead is a ceiling-free reader's only.
     */
    private function assertMayWork(Request $request, Opportunity $opportunity, string $capability): void
    {
        $user = $request->user();
        $lead = $opportunity->lead_id === null ? null : Lead::query()->find($opportunity->lead_id);

        if ($lead === null) {
            abort_unless(app(ProjectReach::class)->ceilingProjectIds() === null && $opportunity->lead_id === null, 403, 'You do not have access to this opportunity.');

            return;
        }

        $projectId = $lead->project_id === null ? null : (string) $lead->project_id;

        abort_unless(
            $projectId === null || app(ProjectAbilities::class)->allows($user, $projectId, $capability),
            403,
            'You do not have this permission on this project.',
        );
        abort_unless(
            app(LeadVisibility::class)->scopeForReader(Lead::query()->whereKey($lead->getKey()), $user, $projectId)->exists(),
            403,
            'You do not have access to this opportunity.',
        );
    }
}
