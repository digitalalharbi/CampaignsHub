<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestEvent;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Services\RequestSla;
use App\Domains\Requests\Services\RequestStatusMachine;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * PLATFORM-SERVICE-INBOX-001 — «الطلبات» in the platform console: the services ordered from the official site.
 *
 * Every query here starts from `platformOwned()`: the scope AND the absent tenant. A subscriber's request —
 * one made on an agency's own domain, or by one of its clients in their portal — is that subscriber's work,
 * and it is not listed, counted, opened or changed from this controller under any filter. The reverse wall is
 * the tenant inbox's own `tenant_id` filter, which a platform request (it has none) can never satisfy.
 *
 * The route group is `auth:sanctum` + `platform`, so a tenant session of any role is refused before this runs.
 */
final class PlatformServiceRequestController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RequestStatusMachine $machine,
        private readonly RequestSla $sla,
    ) {}

    /** GET /admin/service-requests */
    public function index(Request $request): JsonResponse
    {
        $query = $this->inbox()
            ->with(['type', 'status', 'assignee'])
            ->when($request->filled('status'), fn (Builder $q) => $q->whereHas('status', fn (Builder $s) => $s->where('key', $request->string('status')->value())))
            ->when($request->filled('type'), fn (Builder $q) => $q->whereHas('type', fn (Builder $t) => $t->where('key', $request->string('type')->value())))
            ->when($request->boolean('unassigned'), fn (Builder $q) => $q->whereNull('assigned_to'))
            ->when($request->boolean('open'), fn (Builder $q) => $q->whereHas('status', fn (Builder $s) => $s->where('is_terminal', false)))
            ->when(! $request->boolean('include_archived'), fn (Builder $q) => $q->whereNull('archived_at'));

        $page = $query->orderByDesc('submitted_at')->orderByDesc('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        $open = fn (): Builder => $this->inbox()->whereNull('archived_at')
            ->whereHas('status', fn (Builder $s) => $s->where('is_terminal', false));

        return ApiResponse::success([
            'requests' => collect($page->items())->map(fn (ExternalRequest $r): array => $this->row($r))->all(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'counts' => [
                'open' => $open()->count(),
                'unassigned' => $open()->whereNull('assigned_to')->count(),
                'overdue' => $open()->whereNotNull('sla_due_at')->where('sla_due_at', '<', now())->count(),
            ],
            'statuses' => RequestStatus::query()->orderBy('sort')->get(['key', 'name_ar', 'name_en', 'is_terminal'])->all(),
            'administrators' => $this->administrators(),
        ], 'Platform service requests.');
    }

    /** GET /admin/service-requests/{id} */
    public function show(string $id): JsonResponse
    {
        $req = $this->find($id);

        return ApiResponse::success([
            'request' => $this->row($req) + [
                'contact' => [
                    'name' => $req->contact_name,
                    'email' => $req->contact_email,
                    'phone' => $req->contact_phone,
                    'company' => $req->company_name,
                ],
                'objective' => $req->objective,
                'budget' => $req->budget === null ? null : (float) $req->budget,
                'currency' => $req->currency,
                'services' => $req->services ?? [],
                'next_statuses' => $this->machine->allowedFrom($req->status->key),
                'activity' => $req->events()->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get()
                    ->map(fn (RequestEvent $e): array => [
                        'type' => $e->type,
                        'from' => $e->from_status,
                        'to' => $e->to_status,
                        'message' => $e->message,
                        'actor_id' => $e->actor_id,
                        'at' => optional($e->created_at)->toIso8601String(),
                    ])->all(),
            ],
        ], 'Platform service request.');
    }

    /**
     * PATCH /admin/service-requests/{id} — pick the owner, move the status. Each is optional; each is audited.
     *
     * The assignee must be a platform administrator. A subscriber's user can never be put on a platform request:
     * that would hand a visitor's brief to an agency by the back door this whole change closes at the front.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $req = $this->find($id);

        $data = $request->validate([
            'status' => ['sometimes', 'required', 'string', Rule::exists('request_statuses', 'key')],
            'assigned_to' => ['sometimes', 'nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if (array_key_exists('assigned_to', $data)) {
            $assigneeId = $data['assigned_to'];
            if ($assigneeId !== null && ! User::query()->whereKey($assigneeId)->where('is_platform_admin', true)->whereNull('disabled_at')->exists()) {
                throw ValidationException::withMessages(['assigned_to' => __('validation.exists', ['attribute' => 'assigned_to'])]);
            }

            $before = $req->assigned_to;
            if ($before !== $assigneeId) {
                $req->forceFill(['assigned_to' => $assigneeId, 'last_activity_at' => now()])->save();
                $this->event($req, 'assigned', message: $assigneeId === null ? 'Unassigned' : 'Assigned');
                $this->audit->log('platform.service_request.assigned', 'external_request', (string) $req->id,
                    ['assigned_to' => $before], ['assigned_to' => $assigneeId], $data['reason'] ?? null);
            }
        }

        if (isset($data['status'])) {
            $from = $req->status->key;
            $to = $data['status'];

            if ($from !== $to) {
                if (! $this->machine->canTransition($from, $to)) {
                    throw ValidationException::withMessages(['status' => "Cannot move from {$from} to {$to}."]);
                }

                $toStatus = RequestStatus::query()->where('key', $to)->firstOrFail();
                $req->forceFill(['status_id' => $toStatus->id, 'last_activity_at' => now()])->save();

                if ($toStatus->pauses_sla) {
                    $this->sla->pause($req);
                } elseif (RequestStatus::query()->where('key', $from)->value('pauses_sla')) {
                    $this->sla->resume($req);
                }

                $this->event($req, 'status_changed', from: $from, to: $to, clientVisible: (bool) $toStatus->is_client_visible, message: "Status: {$toStatus->name_en}");
                $this->audit->log('platform.service_request.status_changed', 'external_request', (string) $req->id,
                    ['status' => $from], ['status' => $to], $data['reason'] ?? null);
            }
        }

        return $this->show($id);
    }

    /** @return Builder<ExternalRequest> */
    private function inbox(): Builder
    {
        return ExternalRequest::query()->platformOwned();
    }

    private function find(string $id): ExternalRequest
    {
        /** @var ExternalRequest */
        return $this->inbox()->with(['type', 'status', 'assignee'])->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function row(ExternalRequest $r): array
    {
        return [
            'id' => (string) $r->id,
            'reference' => $r->reference,
            'type' => ['key' => $r->type->key, 'name_ar' => $r->type->name_ar, 'name_en' => $r->type->name_en],
            'status' => ['key' => $r->status->key, 'name_ar' => $r->status->name_ar, 'name_en' => $r->status->name_en, 'is_terminal' => (bool) $r->status->is_terminal],
            'priority' => $r->priority,
            'company' => $r->company_name,
            'contact_name' => $r->contact_name,
            'assignee' => $r->assignee === null ? null : ['id' => (int) $r->assignee->id, 'name' => $r->assignee->name],
            'submitted_at' => optional($r->submitted_at)->toIso8601String(),
            'last_activity_at' => optional($r->last_activity_at)->toIso8601String(),
            'sla_due_at' => optional($r->sla_due_at)->toIso8601String(),
            'overdue' => $r->sla_due_at !== null && $r->sla_due_at->isPast() && ! $r->status->is_terminal,
            'source' => $r->source,
        ];
    }

    /** @return list<array{id: int, name: string}> */
    private function administrators(): array
    {
        return User::query()->where('is_platform_admin', true)->whereNull('disabled_at')->orderBy('name')
            ->get(['id', 'name'])->map(fn (User $u): array => ['id' => (int) $u->id, 'name' => (string) $u->name])->all();
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
