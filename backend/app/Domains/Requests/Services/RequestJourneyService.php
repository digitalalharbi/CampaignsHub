<?php

declare(strict_types=1);

namespace App\Domains\Requests\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Requests\Journey\InvalidStageTransitionException;
use App\Domains\Requests\Journey\RequestStage;
use App\Domains\Requests\Journey\StageStatusMap;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Drives an external request through the professional journey state machine (RequestStage).
 *
 * Every transition is validated against RequestStage's transition map, stamps the relevant timestamps /
 * fields (archived_at, on_hold_reason, payment_status), records an immutable audit entry AND a client-safe
 * timeline event, and — on key commercial/delivery milestones — raises an in-app notification through the
 * central NotificationDispatcher with a deep action_url.
 *
 * ADDITIVE: this layer never touches the legacy status_id / RequestStatusMachine flow, and it treats the
 * Billing domain as the system of record for money — payment_status here is only the request-side mirror.
 */
final class RequestJourneyService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationDispatcher $notifications,
    ) {}

    /** The request's current journey stage (defaults to Draft when never set). */
    public function currentStage(ExternalRequest $request): RequestStage
    {
        return RequestStage::tryFrom((string) ($request->journey_stage ?? '')) ?? RequestStage::Draft;
    }

    /**
     * Move a request to a new stage.
     *
     * @throws InvalidStageTransitionException when the map forbids the move.
     */
    public function transition(
        ExternalRequest $request,
        RequestStage $to,
        ?User $actor = null,
        ?string $reason = null,
    ): ExternalRequest {
        $from = $this->currentStage($request);

        if (! RequestStage::canTransition($from, $to)) {
            throw new InvalidStageTransitionException($from, $to);
        }

        return DB::transaction(function () use ($request, $from, $to, $actor, $reason): ExternalRequest {
            $attributes = [
                'journey_stage' => $to->value,
                'last_activity_at' => now(),
            ];

            // Stage-specific stamping.
            if ($to === RequestStage::Archived) {
                $attributes['archived_at'] = $request->archived_at ?? now();
            }
            if ($to === RequestStage::OnHold) {
                $attributes['on_hold_reason'] = $reason;
            } elseif ($from === RequestStage::OnHold) {
                $attributes['on_hold_reason'] = null; // resuming clears the hold reason
            }
            if (($paymentStatus = $to->paymentStatus()) !== null) {
                $attributes['payment_status'] = $paymentStatus;
            }

            /*
             * REQ-UNIFY-001 — the status follows the stage, always.
             *
             * This advanced `journey_stage` and left `status_id` untouched, so a request could sit on
             * stage «paid» with status «under review»: two truthful-looking answers to «where is this?»
             * that disagreed, with nothing on either screen hinting the other existed. The board, the
             * inbox counts and the client's progress bar all read the STATUS, so the stage moving alone
             * was invisible to every reader.
             *
             * A null mapping means «a stage nobody has decided the meaning of» — the status is left
             * exactly where it is rather than being guessed at. See StageStatusMap.
             */
            $statusKey = StageStatusMap::statusFor($to);
            if ($statusKey !== null) {
                $statusId = RequestStatus::where('key', $statusKey)->value('id');
                if ($statusId !== null) {
                    $attributes['status_id'] = $statusId;
                }
            }

            $request->forceFill($attributes)->save();

            // Client-safe timeline event (mirrors how the legacy actions controller records history).
            $request->events()->create([
                'type' => 'journey_transition',
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_id' => $actor?->id,
                'is_client_visible' => $to->isNotifiable(),
                'message' => "Journey: {$from->label()} → {$to->label()}",
                'meta' => array_filter(['reason' => $reason]),
                'created_at' => now(),
            ]);

            // Immutable audit entry through the existing Audit domain.
            $this->audit->log(
                action: 'request.journey.transitioned',
                entityType: 'external_request',
                entityId: $request->id,
                before: ['journey_stage' => $from->value],
                after: array_filter([
                    'journey_stage' => $to->value,
                    'reason' => $reason,
                    'payment_status' => $to->paymentStatus(),
                ], static fn ($v): bool => $v !== null),
                reason: $reason,
                userId: $actor?->id,
                tenantId: $request->tenant_id,
            );

            // Notify on key milestones with a stage-deep action_url.
            if ($to->isNotifiable() && $request->tenant_id !== null) {
                $this->notifications->dispatch([
                    'tenant_id' => $request->tenant_id,
                    'user_id' => $request->assigned_to,
                    'client_workspace_id' => $request->client_id,
                    'type' => "request.journey.{$to->value}",
                    'severity' => 'info',
                    'title' => "Request {$request->reference} → {$to->label()}",
                    'message' => $reason,
                    'source' => 'requests',
                    'entity_type' => 'external_request',
                    'entity_id' => $request->id,
                    'action_url' => "/app/requests/{$request->id}/journey/{$to->value}",
                    'dedup_extra' => $to->value,
                ]);
            }

            return $request;
        });
    }
}
