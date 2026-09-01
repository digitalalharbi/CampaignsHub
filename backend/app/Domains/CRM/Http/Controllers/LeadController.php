<?php

declare(strict_types=1);

namespace App\Domains\CRM\Http\Controllers;

use App\Domains\CRM\Access\LeadVisibility;
use App\Domains\CRM\Actions\AdvanceLead;
use App\Domains\CRM\Actions\ConvertLead;
use App\Domains\CRM\Actions\CreateLead;
use App\Domains\CRM\Actions\UpdateLead;
use App\Domains\CRM\DTOs\LeadData;
use App\Domains\CRM\Enums\LeadStage;
use App\Domains\CRM\Http\Requests\StoreLeadRequest;
use App\Domains\CRM\Http\Requests\UpdateLeadRequest;
use App\Domains\CRM\Models\Lead;
use App\Domains\CRM\Resources\LeadResource;
use App\Domains\CRM\Resources\OpportunityResource;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Context\ProjectContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

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

        /*
         * LEAD-OPERATIONS-001 — the rows this reader is entitled to at all.
         *
         * A lead agent works their own leads. The capability grants the SCREEN; which rows appear on
         * it is a separate question, and it is answered here rather than in the UI — a list that
         * showed everything and a client that filtered it would be one `curl` away from the whole
         * pipeline.
         */
        $visibility = app(LeadVisibility::class);
        $projectId = app(ProjectContext::class)->projectId() ?? ($request->string('project_id')->toString() ?: null);

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        $query = $visibility->scopeForReader($query, $request->user(), $projectId);

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($source = $request->string('source')->toString()) {
            $query->where('source', $source);
        }
        if ($search = $request->string('search')->toString()) {
            /*
             * The search box is part of the redaction.
             *
             * A reader who cannot SEE a phone number but can SEARCH by one has the number: they type
             * it and watch the count change. A redaction with an oracle beside it is not a
             * redaction, so a reader without the identity permission is refused the search rather
             * than quietly given an empty result — which would be a lie about the client's leads.
             */
            abort_unless($visibility->searchable($request->user(), $projectId), 403, 'Searching by name, email or phone needs permission to see them.');

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

        /*
         * A lead an agent was not given is a lead they may not open.
         *
         * The list already hides it; without this the id is enough — and ids are guessable, get
         * pasted into chat, and outlive the reason somebody had one. 403 rather than 404, because
         * the reader is a colleague who should be told to ask.
         */
        abort_unless(
            app(LeadVisibility::class)
                /*
                 * The LEAD's own project is the scope here, not the request's. One lead belongs to
                 * one client, and asking the request would let a reader change the answer by
                 * changing a parameter.
                 */
                ->scopeForReader(
                    Lead::query()->whereKey($lead->getKey()),
                    $request->user(),
                    $lead->project_id === null ? null : (string) $lead->project_id,
                )
                ->exists(),
            403,
            'This lead is not assigned to you.',
        );

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
     * Two checks, because they answer different questions: `leads.update` says «may this person move
     * leads on this client», and the visibility scope says «may they move THIS one». An agent holding
     * the capability may still work only the leads they were given.
     */
    public function advance(Request $request, Lead $lead, AdvanceLead $action): JsonResponse
    {
        $this->assertMayWork($request, $lead, ProjectCapability::LEADS_UPDATE, 'leads.update');

        $data = $request->validate([
            'stage' => ['required', Rule::in(LeadStage::values())],
        ]);

        try {
            $lead = $action->execute($lead, LeadStage::from($data['stage']), $request->user());
        } catch (InvalidArgumentException $e) {
            /*
             * 422, not 403: the mover is entitled to move this lead, and the MOVE is what is wrong.
             * A 403 would send them to ask for a permission they already hold.
             */
            return ApiResponse::error($e->getMessage(), status: 422);
        }

        return ApiResponse::success(new LeadResource($lead), 'Lead moved.');
    }

    /**
     * Hand a lead to somebody, or take it back.
     *
     * `leads.assign`, not `leads.update`: working a lead and deciding who works it are different
     * jobs, and an agent who can quietly pass their difficult leads to a colleague is a pipeline
     * nobody can manage.
     */
    public function assign(Request $request, Lead $lead, AdvanceLead $action): JsonResponse
    {
        $this->assertMayWork($request, $lead, ProjectCapability::LEADS_ASSIGN, 'leads.assign');

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
        $this->assertMayWork($request, $lead, ProjectCapability::LEADS_UPDATE, 'leads.update');

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
     * The capability is asked of the LEAD's own project rather than the request's, so a caller
     * cannot change the answer by changing a parameter. A lead with no project — an older row, a
     * manually created one — falls back to the tenant permission of the same name, which is the
     * layer that owned this question before there was a project layer.
     */
    private function assertMayWork(Request $request, Lead $lead, string $capability, string $tenantPermission): void
    {
        $user = $request->user();
        $projectId = $lead->project_id === null ? null : (string) $lead->project_id;

        abort_unless(
            $user !== null && ($projectId === null
                ? $user->hasPermission($tenantPermission)
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
