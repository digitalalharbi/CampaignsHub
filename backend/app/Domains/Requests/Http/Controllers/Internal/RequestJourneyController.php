<?php

declare(strict_types=1);

namespace App\Domains\Requests\Http\Controllers\Internal;

use App\Domains\Requests\Journey\InvalidStageTransitionException;
use App\Domains\Requests\Journey\RequestStage;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Services\RequestJourneyService;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Permission-gated team endpoint to advance a request through the journey state machine. Kept deliberately
 * thin — all rules live in RequestStage + RequestJourneyService. Reuses the existing requests.change_status
 * permission and tenant scoping (identical to the legacy status action).
 */
final class RequestJourneyController
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly RequestJourneyService $journey,
    ) {}

    /** PATCH /app/requests/{id}/journey */
    public function transition(Request $request, string $id): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('requests.change_status'), 403);

        $data = $request->validate([
            'stage' => ['required', 'string', Rule::in(RequestStage::values())],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var ExternalRequest $req */
        $req = ExternalRequest::query()
            ->where('tenant_id', $this->tenant->tenantId())
            ->findOrFail($id);

        try {
            $this->journey->transition($req, RequestStage::from($data['stage']), $request->user(), $data['reason'] ?? null);
        } catch (InvalidStageTransitionException $e) {
            throw ValidationException::withMessages(['stage' => $e->getMessage()]);
        }

        return response()->json(['data' => [
            'journey_stage' => $req->journey_stage,
            'payment_status' => $req->payment_status,
        ]]);
    }
}
