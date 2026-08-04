<?php

declare(strict_types=1);

namespace App\Domains\Requests\Http\Controllers\Internal;

use App\Domains\Billing\Models\Quote;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestConversion;
use App\Domains\Requests\Services\RequestSla;
use App\Domains\Requests\Support\RequestLabels;
use App\Domains\Taxonomy\Services\PaidServiceCatalog;
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
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly RequestSla $sla,
        private readonly PaidServiceCatalog $paidServices,
    ) {}

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
            'meta' => [
                'total' => $page->total(), 'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'summary' => $this->summary(clone $query),
                'breakdown' => $this->breakdown(clone $query),
            ],
        ]);
    }

    /**
     * REQ-CHARTS-001 — the shape of the queue: by status, by service, and against the SLA.
     *
     * Three groupings rather than one chart, because they answer three different questions an operator
     * actually asks: «where is everything?» (status), «what kind of work is this?» (service type) and
     * «are we late?» (SLA). Each is one grouped query over the SAME filtered builder the list uses, so
     * a chart can never describe a different set from the table beneath it.
     *
     * The SLA bucket is the one worth naming: `breached` is a promise already missed, `due_soon` is one
     * about to be, and they are counted separately because they need different actions — the first is
     * an apology, the second is a reprioritisation.
     *
     * @param  Builder<ExternalRequest>  $query
     * @return array<string, mixed>
     */
    private function breakdown(Builder $query): array
    {
        $base = $query->reorder()->toBase();

        $byStatus = (clone $base)
            ->join('request_statuses', 'request_statuses.id', '=', 'external_requests.status_id')
            ->selectRaw('request_statuses.key, request_statuses.name_ar, request_statuses.name_en, COUNT(*) AS total')
            ->groupBy('request_statuses.key', 'request_statuses.name_ar', 'request_statuses.name_en')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r): array => [
                'key' => (string) $r->key,
                'label' => (string) $r->name_ar,
                'label_en' => (string) $r->name_en,
                'total' => (int) $r->total,
            ])->all();

        $byType = (clone $base)
            ->join('request_types', 'request_types.id', '=', 'external_requests.type_id')
            ->selectRaw('request_types.key, request_types.name_ar, request_types.name_en, COUNT(*) AS total')
            ->groupBy('request_types.key', 'request_types.name_ar', 'request_types.name_en')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($r): array => [
                'key' => (string) $r->key,
                'label' => (string) $r->name_ar,
                'label_en' => (string) $r->name_en,
                'total' => (int) $r->total,
            ])->all();

        $sla = (array) (clone $base)
            ->selectRaw('COUNT(*) FILTER (WHERE sla_breached_at IS NOT NULL) AS breached')
            ->selectRaw('COUNT(*) FILTER (WHERE sla_breached_at IS NULL AND sla_due_at IS NOT NULL AND sla_due_at <= NOW() + INTERVAL \'24 hours\') AS due_soon')
            ->selectRaw('COUNT(*) FILTER (WHERE sla_breached_at IS NULL AND (sla_due_at IS NULL OR sla_due_at > NOW() + INTERVAL \'24 hours\')) AS on_track')
            ->first();

        return [
            'by_status' => $byStatus,
            'by_type' => $byType,
            'sla' => [
                'breached' => (int) ($sla['breached'] ?? 0),
                'due_soon' => (int) ($sla['due_soon'] ?? 0),
                'on_track' => (int) ($sla['on_track'] ?? 0),
            ],
        ];
    }

    /**
     * REQ-SUMMARY-001 — the real counts, over the whole filtered set.
     *
     * The inbox header used to compute these in the browser from `rows`, which is ONE PAGE. With 493
     * requests and a hundred loaded, «جديد ٨٧» was eighty-seven out of the first hundred, presented
     * beside a total of 493 as though the two were measured the same way. A card that answers «what is
     * happening?» with a number silently scoped to whatever happened to be fetched is worse than no
     * card: it is confidently wrong, and nothing on the page hints at it.
     *
     * Counted in ONE grouped query rather than five, and over a clone of the same builder — so the
     * summary always describes exactly the set the list is showing, filters and all. A summary that
     * ignored the active filter would be a different lie in the same place.
     *
     * `needs_attention` is the actionable one: breached SLA or nobody assigned. It is what turns a
     * status board into something that answers «what needs me?» rather than only «what exists?».
     *
     * @param  Builder<ExternalRequest>  $query
     * @return array<string, int>
     */
    private function summary(Builder $query): array
    {
        /*
         * `reorder()` first — the ORDER BY has to go before this is aggregated.
         *
         * `paginate()` mutates the builder it is called on, so by the time this clone is taken the
         * sort column is already attached; Postgres then refuses the aggregate because
         * `submitted_at` is neither grouped nor aggregated. A 500 on the busiest screen in the
         * product, from a line that reads as though it only counts rows.
         */
        $row = (array) $query->reorder()->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("COUNT(*) FILTER (WHERE status_id = (SELECT id FROM request_statuses WHERE key = 'new')) AS new_count")
            ->selectRaw("COUNT(*) FILTER (WHERE status_id = (SELECT id FROM request_statuses WHERE key = 'under_review')) AS review_count")
            ->selectRaw("COUNT(*) FILTER (WHERE status_id IN (SELECT id FROM request_statuses WHERE key IN ('waiting_client', 'on_hold'))) AS paused_count")
            ->selectRaw('COUNT(*) FILTER (WHERE sla_breached_at IS NOT NULL OR assigned_to IS NULL) AS needs_attention')
            ->first();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'new' => (int) ($row['new_count'] ?? 0),
            'review' => (int) ($row['review_count'] ?? 0),
            'paused' => (int) ($row['paused_count'] ?? 0),
            'needs_attention' => (int) ($row['needs_attention'] ?? 0),
        ];
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

        // Canonical selected services (request_services), resolved to display labels; fall back to the jsonb.
        $serviceKeys = $req->requestServices()->orderBy('position')->pluck('service_key')->all();
        if ($serviceKeys === []) {
            $serviceKeys = array_values(array_filter((array) ($req->services ?? []), 'is_string'));
        }

        return response()->json(['data' => array_merge($this->row($req), [
            'objective' => $req->objective,
            'services' => $serviceKeys,
            'services_resolved' => $this->paidServices->resolve($serviceKeys),
            'service_details' => $req->service_details,
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
            'conversion' => $this->conversion($req),
            'billing' => $this->billing($req),
        ])]);
    }

    /**
     * Quotes raised FROM this request, each with its issued invoice (if any) — the request's billing thread.
     *
     * @return list<array<string,mixed>>
     */
    private function billing(ExternalRequest $req): array
    {
        return Quote::query()
            ->where('external_request_id', $req->id)
            ->with('invoices')
            ->orderByDesc('created_at')->limit(10)->get()
            ->map(fn ($q) => [
                'quote_id' => (string) $q->getKey(),
                'number' => $q->number,
                'status' => $q->status,
                'total' => (string) $q->total,
                'currency' => $q->currency,
                'tax_treatment' => $q->tax_treatment,
                'invoice' => $q->invoices->first() !== null ? [
                    'invoice_id' => (string) $q->invoices->first()->getKey(),
                    'number' => $q->invoices->first()->number,
                    'status' => $q->invoices->first()->status,
                    'total' => (string) $q->invoices->first()->total,
                    'amount_paid' => (string) $q->invoices->first()->amount_paid,
                ] : null,
            ])->all();
    }

    /** @return array<string,mixed>|null the completed conversion result (client/project/campaign), if any */
    private function conversion(ExternalRequest $req): ?array
    {
        $c = RequestConversion::where('tenant_id', $req->tenant_id)
            ->where('external_request_id', $req->id)->where('status', 'completed')->first();
        if ($c === null) {
            return null;
        }

        return [
            'client_id' => $c->client_id,
            'project_id' => $c->project_id,
            'campaign_id' => $c->campaign_id,
            'completed_at' => optional($c->completed_at)->toIso8601String(),
        ];
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
            'services' => $r->services ?? [],
            'status' => $r->status->key,
            /*
             * REQ-LABELS-001 — both languages, and the reader picks.
             *
             * This served `name_en` only, in a product whose default locale is Arabic and whose status
             * table has carried `name_ar` since it was created. The inbox therefore showed «Under
             * Review» and «New» in an otherwise Arabic page — the untranslated code the operator was
             * meant never to see. Serving both is the pattern the taxonomy engine already uses, and it
             * has the second advantage that toggling language does not need a refetch.
             */
            'status_label' => $r->status->name_ar,
            'status_label_en' => $r->status->name_en,
            'priority' => $r->priority,
            // Priority had no Arabic AT ALL — the raw key («medium») was rendered straight into the table.
            'priority_label' => RequestLabels::priority($r->priority, 'ar'),
            'priority_label_en' => RequestLabels::priority($r->priority, 'en'),
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
