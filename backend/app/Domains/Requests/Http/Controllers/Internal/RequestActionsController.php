<?php

declare(strict_types=1);

namespace App\Domains\Requests\Http\Controllers\Internal;

use App\Domains\Audit\AuditLogger;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Services\RequestNotifier;
use App\Domains\Requests\Services\RequestSla;
use App\Domains\Requests\Services\RequestStatusMachine;
use App\Domains\Tenancy\Context\TenantContext;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Permission-gated write actions on a request. All are tenant-scoped; each records an event + audit and
 * fires an in-app notification. Status changes go through the state machine; SLA pauses/resumes here —
 * never in the frontend.
 */
final class RequestActionsController
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly RequestNotifier $notifier,
        private readonly RequestSla $sla,
        private readonly RequestStatusMachine $machine,
    ) {}

    /** PATCH /app/requests/{request}/assign */
    public function assign(Request $request, string $id): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('requests.assign'), 403);
        $req = $this->find($id);
        $data = $request->validate(['assignee_id' => ['nullable', 'integer']]);

        $assigneeId = $data['assignee_id'] ?? null;
        if ($assigneeId !== null) {
            // The assignee MUST belong to the same tenant — never assign outside the org.
            $ok = User::where('id', $assigneeId)->where('tenant_id', $this->tenant->tenantId())->exists();
            abort_unless($ok, 422, 'Assignee must be a member of this workspace.');
        }

        $from = $req->assigned_to;
        $req->forceFill(['assigned_to' => $assigneeId, 'last_activity_at' => now()])->save();
        $this->event($req, 'assigned', message: $assigneeId ? 'Assigned' : 'Unassigned');
        $this->audit->log('request.assigned', 'external_request', $req->id, ['assigned_to' => $from], ['assigned_to' => $assigneeId]);
        if ($assigneeId) {
            $this->notifier->notify($req, 'request.assigned', 'Request assigned to you', $req->reference, $assigneeId);
        }

        return response()->json(['data' => ['status' => 'assigned']]);
    }

    /** PATCH /app/requests/{request}/status */
    public function changeStatus(Request $request, string $id): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('requests.change_status'), 403);
        $req = $this->find($id);
        $data = $request->validate([
            'status' => ['required', 'string', Rule::exists('request_statuses', 'key')],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $from = $req->status->key;
        $to = $data['status'];
        if (! $this->machine->canTransition($from, $to)) {
            throw ValidationException::withMessages(['status' => "Cannot move from {$from} to {$to}."]);
        }

        $toStatus = RequestStatus::where('key', $to)->firstOrFail();
        $req->forceFill(['status_id' => $toStatus->id, 'last_activity_at' => now()])->save();

        // SLA pauses when entering a pausing status (e.g. waiting_client), resumes when leaving it.
        if ($toStatus->pauses_sla) {
            $this->sla->pause($req);
        } elseif (RequestStatus::where('key', $from)->value('pauses_sla')) {
            $this->sla->resume($req);
        }

        $this->event($req, 'status_changed', from: $from, to: $to, clientVisible: $toStatus->is_client_visible, message: "Status: {$toStatus->name_en}");
        $this->audit->log('request.status_changed', 'external_request', $req->id, ['status' => $from], ['status' => $to, 'reason' => $data['reason'] ?? null]);
        $this->notifier->notify($req, 'request.status_changed', "Request {$req->reference} → {$toStatus->name_en}");

        return response()->json(['data' => ['status' => 'updated']]);
    }

    /** PATCH /app/requests/{request}/priority */
    public function changePriority(Request $request, string $id): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('requests.change_priority'), 403);
        $req = $this->find($id);
        $data = $request->validate(['priority' => ['required', Rule::in(['critical', 'high', 'medium', 'low'])]]);

        $from = $req->priority;
        $req->forceFill(['priority' => $data['priority'], 'last_activity_at' => now()])->save();
        $this->event($req, 'priority_changed', message: "Priority: {$data['priority']}");
        $this->audit->log('request.priority_changed', 'external_request', $req->id, ['priority' => $from], ['priority' => $data['priority']]);

        return response()->json(['data' => ['status' => 'updated']]);
    }

    /** POST /app/requests/{request}/request-information — ask the client, pause SLA. */
    public function requestInformation(Request $request, string $id): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('requests.request_information'), 403);
        $req = $this->find($id);
        $data = $request->validate(['message' => ['required', 'string', 'min:2', 'max:2000']]);

        $req->comments()->create(['visibility' => 'client', 'author_label' => 'Team', 'body' => $data['message']]);
        $waiting = RequestStatus::where('key', 'waiting_client')->firstOrFail();
        $req->forceFill(['status_id' => $waiting->id, 'last_activity_at' => now()])->save();
        $this->sla->pause($req);
        $this->event($req, 'info_requested', to: 'waiting_client', clientVisible: true, message: 'Information requested');
        $this->audit->log('request.info_requested', 'external_request', $req->id);
        $this->notifier->notify($req, 'request.info_requested', "Info requested on {$req->reference}");

        return response()->json(['data' => ['status' => 'requested']]);
    }

    /** POST /app/requests/{request}/internal-note — team-only note, never client-visible. */
    public function addInternalNote(Request $request, string $id): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('requests.comment_internal'), 403);
        $req = $this->find($id);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:2000']]);

        $req->comments()->create(['visibility' => 'internal', 'author_id' => $request->user()->id, 'body' => $data['body']]);
        $this->event($req, 'internal_note', clientVisible: false, message: 'Internal note added');
        $this->audit->log('request.internal_note', 'external_request', $req->id);

        return response()->json(['data' => ['status' => 'noted']]);
    }

    /** POST /app/requests/{request}/reply — client-visible reply from the team. */
    public function replyToClient(Request $request, string $id): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('requests.comment_client'), 403);
        $req = $this->find($id);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:2000']]);

        $req->comments()->create(['visibility' => 'client', 'author_label' => 'Team', 'body' => $data['body']]);
        $this->event($req, 'comment', clientVisible: true, message: 'Team replied');
        $this->audit->log('request.client_reply', 'external_request', $req->id);

        return response()->json(['data' => ['status' => 'sent']]);
    }

    /** PATCH /app/requests/{request}/archive */
    public function archive(string $id): JsonResponse
    {
        abort_unless(request()->user()?->hasPermission('requests.archive'), 403);
        $req = $this->find($id);
        $archived = RequestStatus::where('key', 'archived')->firstOrFail();
        $req->forceFill(['status_id' => $archived->id, 'archived_at' => now(), 'last_activity_at' => now()])->save();
        $this->event($req, 'status_changed', to: 'archived', clientVisible: false, message: 'Archived');
        $this->audit->log('request.archived', 'external_request', $req->id);

        return response()->json(['data' => ['status' => 'archived']]);
    }

    private function find(string $id): ExternalRequest
    {
        /** @var ExternalRequest */
        return ExternalRequest::query()->where('tenant_id', $this->tenant->tenantId())->with(['type', 'status'])->findOrFail($id);
    }

    private function event(ExternalRequest $req, string $type, ?string $from = null, ?string $to = null, bool $clientVisible = false, ?string $message = null): void
    {
        $req->events()->create([
            'type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => request()->user()?->id,
            'is_client_visible' => $clientVisible,
            'message' => $message,
            'created_at' => now(),
        ]);
    }
}
