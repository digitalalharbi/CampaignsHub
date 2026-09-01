<?php

declare(strict_types=1);

namespace App\Domains\CRM\Http\Controllers;

use App\Domains\CRM\Actions\AdvanceLead;
use App\Domains\CRM\Actions\ConvertLead;
use App\Domains\CRM\Actions\CreateLead;
use App\Domains\CRM\Actions\UpdateLead;
use App\Domains\CRM\Enums\LeadStage;
use App\Domains\CRM\DTOs\LeadData;
use App\Domains\CRM\Http\Requests\StoreLeadRequest;
use App\Domains\CRM\Http\Requests\UpdateLeadRequest;
use App\Domains\CRM\Models\Lead;
use App\Domains\CRM\Resources\LeadResource;
use App\Domains\CRM\Resources\OpportunityResource;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Illuminate\Http\Request;

final class LeadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('leads.view'), 403);

        /*
         * LEAD-DEDUP-001 — «received» and «unique» are DIFFERENT figures, and both are reported.
         *
         * A duplicate is never deleted and never hidden by default: the same person arriving twice
         * is a fact about the campaign that produced them, and a list that quietly showed one row
         * would be answering a question nobody asked while losing the provenance of the other
         * arrival. So the rows are all here, `unique=1` narrows to the canonicals, and the counts
         * for BOTH are always in the response — a reader who sees 412 leads should be able to find
         * out, without changing anything, that 389 of them are people.
         */
        $query = Lead::query()->latest()->withCount('duplicates');

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($source = $request->string('source')->toString()) {
            $query->where('source', $source);
        }
        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }

        /*
         * The counts are taken BEFORE the unique filter narrows the query, and both from the same
         * builder, so they describe one scope. Counting «received» over an unfiltered table would
         * report a number that disagrees with the list beside it the moment a status or a search is
         * applied — two figures on one screen that cannot both be about what the reader is looking
         * at.
         */
        $received = (clone $query)->toBase()->getCountForPagination();
        $unique = (clone $query)->whereNull('canonical_lead_id')->toBase()->getCountForPagination();

        if ($request->boolean('unique')) {
            $query->whereNull('canonical_lead_id');
        }

        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);
        $leads = $query->paginate($perPage)->withQueryString();

        return ApiResponse::success(
            LeadResource::collection($leads->items()),
            'Leads retrieved.',
            meta: [
                'pagination' => [
                    'total' => $leads->total(),
                    'per_page' => $leads->perPage(),
                    'current_page' => $leads->currentPage(),
                    'last_page' => $leads->lastPage(),
                ],
                /*
                 * Named for what they are. `received` is arrivals; `unique` is people. A single
                 * «total» would have to be one of them, and whichever it was would be wrong for the
                 * other question — under-reporting the campaign's volume or over-reporting its
                 * audience, with nothing on screen to say which.
                 */
                'counts' => ['received' => $received, 'unique' => $unique],
            ],
        );
    }

    public function store(StoreLeadRequest $request, CreateLead $action): JsonResponse
    {
        $lead = $action->execute(LeadData::fromArray($request->validated()));

        return ApiResponse::success(new LeadResource($lead), 'Lead created.', status: 201);
    }

    public function show(Request $request, Lead $lead): JsonResponse
    {
        abort_unless($request->user()->hasPermission('leads.view'), 403);
        $lead->load('activities');

        return ApiResponse::success(new LeadResource($lead), 'Lead retrieved.');
    }

    public function update(UpdateLeadRequest $request, Lead $lead, UpdateLead $action): JsonResponse
    {
        $lead = $action->execute($lead, LeadData::fromArray(array_merge($lead->toArray(), $request->validated())));

        return ApiResponse::success(new LeadResource($lead), 'Lead updated.');
    }

    public function destroy(Request $request, Lead $lead): JsonResponse
    {
        abort_unless($request->user()->hasPermission('leads.delete'), 403);
        $lead->delete();

        return ApiResponse::success(null, 'Lead deleted.', status: 200);
    }

    public function convert(Request $request, Lead $lead, ConvertLead $action): JsonResponse
    {
        abort_unless($request->user()->hasPermission('leads.convert'), 403);

        $opportunity = $action->execute($lead, $request->string('opportunity_name')->toString() ?: null);

        return ApiResponse::success(
            new OpportunityResource($opportunity),
            'Lead converted to opportunity.',
            status: 201,
        );
    }

    /**
     * LEAD-OPERATIONS-001 — move a lead along the pipeline.
     *
     * Guarded by `leads.update` on the lead's OWN project, and by the same visibility rule the list
     * uses: an agent may work the leads they were given and no others. Both checks, because the
     * first says «may this person move leads here» and the second says «may they move THIS one».
     */
    public function advance(Request $request, Lead $lead, AdvanceLead $action): JsonResponse
    {
        $this->assertMayWork($request, $lead, ProjectCapability::LEADS_UPDATE);

        $data = $request->validate([
            'stage' => ['required', Rule::in(LeadStage::values())],
        ]);

        try {
            $lead = $action->execute($lead, LeadStage::from($data['stage']), $request->user());
        } catch (InvalidArgumentException $e) {
            /*
             * 422, not 403: the mover is entitled to move this lead, and the move itself is the
             * thing that is wrong. A 403 would send them to ask for a permission they already have.
             */
            return ApiResponse::error($e->getMessage(), status: 422);
        }

        return ApiResponse::success(new LeadResource($lead), 'Lead moved.');
    }

    /**
     * Hand a lead to somebody, or take it back.
     *
     * `leads.assign` and not `leads.update`: working a lead and deciding who works it are different
     * jobs, and an agent who can quietly reassign their own difficult leads to a colleague is a
     * pipeline nobody can manage.
     */
    public function assign(Request $request, Lead $lead, AdvanceLead $action): JsonResponse
    {
        $this->assertMayWork($request, $lead, ProjectCapability::LEADS_ASSIGN);

        $data = $request->validate([
            'owner_id' => ['present', 'nullable', 'integer', 'exists:users,id'],
        ]);

        return ApiResponse::success(
            new LeadResource($action->assign($lead, $data['owner_id'] === null ? null : (int) $data['owner_id'])),
            'Lead assigned.',
        );
    }

    /** Record what the agent promised. Null clears it — «no call-back planned» is an answer. */
    public function followUp(Request $request, Lead $lead, AdvanceLead $action): JsonResponse
    {
        $this->assertMayWork($request, $lead, ProjectCapability::LEADS_UPDATE);

        $data = $request->validate([
            'next_follow_up_at' => ['present', 'nullable', 'date'],
        ]);

        return ApiResponse::success(
            new LeadResource($action->scheduleFollowUp(
                $lead,
                $data['next_follow_up_at'] === null ? null : Carbon::parse($data['next_follow_up_at']),
            )),
            'Follow-up recorded.',
        );
    }

    /**
     * Both halves of «may this person do this to THIS lead», in one place.
     *
     * The capability is asked of the lead's own project rather than the request's, so a reader
     * cannot change the answer by changing a parameter. The visibility scope is asked as well,
     * because a capability grants the ACT and the scope decides which rows it may be performed on —
     * an agent holding `leads.update` may still only update the leads they were given.
     */
    private function assertMayWork(Request $request, Lead $lead, string $capability): void
    {
        $user = $request->user();
        $projectId = $lead->project_id === null ? null : (string) $lead->project_id;

        abort_unless(
            $user !== null && ($projectId === null
                ? $user->hasPermission(str_replace('.', '.', $capability))
                : app(ProjectAbilities::class)->allows($user, $projectId, $capability)),
            403,
            'You do not have this permission on this project.',
        );

        abort_unless(
            app(LeadVisibility::class)
                ->scopeForReader(Lead::query()->whereKey($lead->getKey()), $user, $projectId)
                ->exists(),
            403,
            'This lead is not assigned to you.',
        );
    }

}
