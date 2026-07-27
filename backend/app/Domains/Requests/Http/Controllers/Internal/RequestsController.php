<?php

declare(strict_types=1);

namespace App\Domains\Requests\Http\Controllers\Internal;

use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Services\RequestSla;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Internal requests dashboard API. Every query is scoped to the current tenant explicitly (the model
 * is intentionally not globally scoped, since public intake/tracking must read it). Never relies on the
 * frontend to hide another tenant's rows.
 */
final class RequestsController
{
    public function __construct(private readonly TenantContext $tenant, private readonly RequestSla $sla) {}

    /** GET /api/v1/app/requests — filtered, sorted, paginated list for the current tenant. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('requests.view'), 403);

        $query = $this->scoped()
            ->with(['type', 'status', 'assignee'])
            ->when($request->filled('status'), fn (Builder $q) => $q->whereHas('status', fn (Builder $s) => $s->where('key', $request->string('status'))))
            ->when($request->filled('type'), fn (Builder $q) => $q->whereHas('type', fn (Builder $t) => $t->where('key', $request->string('type'))))
            ->when($request->filled('module'), fn (Builder $q) => $q->where('module', $request->string('module')))
            ->when($request->filled('priority'), fn (Builder $q) => $q->where('priority', $request->string('priority')))
            ->when($request->filled('assignee'), fn (Builder $q) => $q->where('assigned_to', $request->integer('assignee')))
            ->when($request->boolean('unassigned'), fn (Builder $q) => $q->whereNull('assigned_to'))
            ->when($request->filled('source'), fn (Builder $q) => $q->where('source', $request->string('source')))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('submitted_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('submitted_at', '<=', $request->date('to')))
            ->when(! $request->boolean('include_archived'), fn (Builder $q) => $q->whereNull('archived_at'))
            ->when($request->filled('q'), function (Builder $q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn (Builder $w) => $w
                    ->where('reference', 'like', $term)
                    ->orWhere('contact_name', 'like', $term)
                    ->orWhere('company_name', 'like', $term)
                    ->orWhere('contact_email', 'like', $term));
            });

        $sort = in_array($request->string('sort')->value(), ['submitted_at', 'sla_due_at', 'priority', 'last_activity_at'], true)
            ? $request->string('sort')->value() : 'submitted_at';
        $dir = $request->string('direction')->value() === 'asc' ? 'asc' : 'desc';

        $page = $query->orderBy($sort, $dir)->paginate(min($request->integer('per_page', 20), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (ExternalRequest $r) => $this->row($r))->all(),
            'meta' => ['total' => $page->total(), 'per_page' => $page->perPage(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** GET /api/v1/app/requests/{request} — full internal detail (includes internal notes + SLA). */
    public function show(Request $request, string $id): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('requests.view'), 403);
        $req = $this->find($id);

        $comments = $req->comments()->orderBy('created_at')->get()
            ->map(fn ($c) => ['id' => $c->id, 'visibility' => $c->visibility, 'author' => $c->author_label ?? 'Team', 'body' => $c->body, 'at' => optional($c->created_at)->toIso8601String()]);
        $events = $req->events()->orderByDesc('created_at')->limit(100)->get()
            ->map(fn ($e) => ['type' => $e->type, 'from' => $e->from_status, 'to' => $e->to_status, 'message' => $e->message, 'client_visible' => $e->is_client_visible, 'at' => optional($e->created_at)->toIso8601String()]);
        $files = $req->files()->whereNotNull('request_id')->get()
            ->map(fn ($f) => ['id' => $f->id, 'name' => $f->original_name, 'size' => $f->size, 'client_visible' => $f->is_client_visible]);

        return response()->json(['data' => array_merge($this->row($req), [
            'objective' => $req->objective,
            'contact_email' => $req->contact_email,
            'contact_phone' => $req->contact_phone,
            'company_name' => $req->company_name,
            'budget' => $req->budget,
            'currency' => $req->currency,
            'metadata' => $req->metadata,
            'sla' => [
                'due_at' => optional($req->sla_due_at)->toIso8601String(),
                'started_at' => optional($req->sla_started_at)->toIso8601String(),
                'paused_at' => optional($req->sla_paused_at)->toIso8601String(),
                'breached_at' => optional($req->sla_breached_at)->toIso8601String(),
                'remaining_seconds' => $this->sla->remainingSeconds($req),
            ],
            'comments' => $comments,
            'events' => $events,
            'files' => $files,
            'archived_at' => optional($req->archived_at)->toIso8601String(),
        ])]);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<ExternalRequest> */
    private function scoped(): \Illuminate\Database\Eloquent\Builder
    {
        return ExternalRequest::query()->where('tenant_id', $this->tenant->tenantId());
    }

    public function find(string $id): ExternalRequest
    {
        /** @var ExternalRequest */
        return $this->scoped()->with(['type', 'status', 'assignee'])->findOrFail($id);
    }

    /** @return array<string,mixed> */
    private function row(ExternalRequest $r): array
    {
        return [
            'id' => $r->id,
            'reference' => $r->reference,
            'service' => $r->type->name_en,
            'service_ar' => $r->type->name_ar,
            'module' => $r->module,
            'status' => $r->status->key,
            'status_label' => $r->status->name_en,
            'priority' => $r->priority,
            'contact' => $r->company_name ?: $r->contact_name,
            'assignee' => $r->assignee?->name,
            'assigned_to' => $r->assigned_to,
            'source' => $r->source,
            'sla_due_at' => optional($r->sla_due_at)->toIso8601String(),
            'sla_breached' => $r->sla_breached_at !== null,
            'submitted_at' => optional($r->submitted_at)->toIso8601String(),
            'last_activity_at' => optional($r->last_activity_at)->toIso8601String(),
        ];
    }
}
