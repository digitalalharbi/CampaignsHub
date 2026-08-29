<?php

declare(strict_types=1);

namespace App\Domains\CRM\Http\Controllers;

use App\Domains\CRM\Actions\ConvertLead;
use App\Domains\CRM\Actions\CreateLead;
use App\Domains\CRM\Actions\UpdateLead;
use App\Domains\CRM\DTOs\LeadData;
use App\Domains\CRM\Http\Requests\StoreLeadRequest;
use App\Domains\CRM\Http\Requests\UpdateLeadRequest;
use App\Domains\CRM\Models\Lead;
use App\Domains\CRM\Resources\LeadResource;
use App\Domains\CRM\Resources\OpportunityResource;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
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
}
