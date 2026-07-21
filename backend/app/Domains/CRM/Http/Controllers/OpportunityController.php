<?php

declare(strict_types=1);

namespace App\Domains\CRM\Http\Controllers;

use App\Domains\CRM\Models\Opportunity;
use App\Domains\CRM\Models\PipelineStage;
use App\Domains\CRM\Resources\OpportunityResource;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OpportunityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('leads.view'), 403);

        $opportunities = Opportunity::query()
            ->with('stage')
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
        $opportunity->load('stage');

        return ApiResponse::success(new OpportunityResource($opportunity), 'Opportunity retrieved.');
    }

    /** Move an opportunity to another stage of its pipeline. */
    public function updateStage(Request $request, Opportunity $opportunity): JsonResponse
    {
        abort_unless($request->user()->hasPermission('leads.update'), 403);

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

        return ApiResponse::success(new OpportunityResource($opportunity->load('stage')), 'Stage updated.');
    }
}
