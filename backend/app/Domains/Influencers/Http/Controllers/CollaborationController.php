<?php

declare(strict_types=1);

namespace App\Domains\Influencers\Http\Controllers;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Influencers\Models\InfluencerCollaboration;
use App\Domains\Influencers\Models\InfluencerDeliverable;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Collaborations and their deliverables (INFL-001).
 *
 * Unlike the roster, this IS narrowed by the membership's client scope, through the same
 * `ClientScopeResolver` every other client-bound surface uses — an account manager responsible for
 * three clients sees three clients' agreements and no others. There is no second isolation mechanism
 * here to drift out of step with the first.
 *
 * Money is two separate figures. `agreed_fee` is billed to the client; `influencer_fee` is paid to
 * the creator; the margin between them is derived, and only for someone allowed to see costs. A
 * caller without that permission gets neither the creator's fee nor the margin — not a zero, and not
 * a rounded figure that could be worked backwards.
 */
final class CollaborationController extends Controller
{
    public function __construct(private readonly ClientScopeResolver $scopes) {}

    /** GET /api/v1/influencers/collaborations */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('influencers.view'), 403);

        $query = InfluencerCollaboration::query()
            ->with(['influencer', 'client', 'deliverables'])
            ->orderByDesc('created_at');

        $this->constrain($query, $request);

        if (($status = $request->query('status')) !== null && $status !== '') {
            $query->where('status', $status);
        }
        if (($client = $request->query('client_id')) !== null && $client !== '') {
            $query->where('client_workspace_id', $client);
        }

        $page = $query->paginate(25);
        $canSeeCosts = (bool) $user->hasPermission('influencers.view_costs');

        return ApiResponse::success([
            'collaborations' => collect($page->items())
                ->map(fn (InfluencerCollaboration $c) => $this->present($c, $canSeeCosts))->all(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage()],
            'can_manage' => (bool) $user->hasPermission('influencers.manage'),
            'can_see_costs' => $canSeeCosts,
        ], 'Collaborations.');
    }

    /** POST /api/v1/influencers/collaborations */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('influencers.manage'), 403);

        $data = $request->validate([
            'influencer_id' => ['required', 'string', Rule::exists('influencers', 'id')],
            'client_workspace_id' => ['nullable', 'string', Rule::exists('client_workspaces', 'id')],
            'campaign_id' => ['nullable', 'string', Rule::exists('unified_campaigns', 'id')],
            'title' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:64'],
            'currency' => ['nullable', 'string', 'size:3'],
            'agreed_fee' => ['nullable', 'numeric', 'min:0'],
            'influencer_fee' => ['nullable', 'numeric', 'min:0'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'brief' => ['nullable', 'string', 'max:10000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        // A collaboration for a client the caller cannot reach would be invisible the moment it was
        // saved — and would hand that client's name to someone with no access to it.
        if (($clientId = $data['client_workspace_id'] ?? null) !== null) {
            abort_unless($this->scopes->canReach($user, (string) $clientId), 403);
            abort_unless(
                ClientWorkspace::query()->whereKey($clientId)->exists(),
                404,
            );
        }

        $collaboration = InfluencerCollaboration::create($data);

        return ApiResponse::success(
            ['collaboration' => $this->present($collaboration->load(['influencer', 'client', 'deliverables']), true)],
            'Collaboration created.',
            [],
            201,
        );
    }

    /** GET /api/v1/influencers/collaborations/{collaboration} */
    public function show(Request $request, string $collaboration): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('influencers.view'), 403);

        $model = $this->reachable($request, $collaboration);

        return ApiResponse::success(
            ['collaboration' => $this->present($model, (bool) $user->hasPermission('influencers.view_costs'))],
            'Collaboration.',
        );
    }

    /** POST /api/v1/influencers/collaborations/{collaboration}/deliverables */
    public function addDeliverable(Request $request, string $collaboration): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('influencers.manage'), 403);

        $model = $this->reachable($request, $collaboration);

        $data = $request->validate([
            'type' => ['required', 'string', 'max:64'],
            'platform' => ['nullable', 'string', 'max:64'],
            'due_on' => ['nullable', 'date'],
        ]);

        $model->deliverables()->create($data + ['tenant_id' => $model->tenant_id, 'status' => 'pending']);

        return ApiResponse::success(
            ['collaboration' => $this->present($model->fresh(['influencer', 'client', 'deliverables']), (bool) $user->hasPermission('influencers.view_costs'))],
            'Deliverable added.',
            [],
            201,
        );
    }

    /**
     * PATCH /api/v1/influencers/collaborations/{collaboration}/deliverables/{deliverable}.
     *
     * The timestamps are set from the STATUS rather than accepted from the caller: a deliverable
     * marked published with no `published_at`, or approved with a date someone typed, makes the
     * "what is late?" question unanswerable.
     */
    public function updateDeliverable(Request $request, string $collaboration, string $deliverable): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('influencers.manage'), 403);

        $model = $this->reachable($request, $collaboration);

        /** @var InfluencerDeliverable|null $item */
        $item = $model->deliverables()->whereKey($deliverable)->first();
        abort_if($item === null, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'submitted', 'approved', 'rejected', 'published', 'cancelled'])],
            'submitted_url' => ['nullable', 'url', 'max:2048'],
            'feedback' => ['nullable', 'string', 'max:5000'],
            'due_on' => ['nullable', 'date'],
        ]);

        $item->fill($data);

        match ($data['status']) {
            'submitted' => $item->submitted_at = now(),
            'approved' => $item->approved_at = now(),
            'published' => $item->published_at = now(),
            default => null,
        };

        $item->save();

        return ApiResponse::success(
            ['collaboration' => $this->present($model->fresh(['influencer', 'client', 'deliverables']), (bool) $user->hasPermission('influencers.view_costs'))],
            'Deliverable updated.',
        );
    }

    /** The collaboration, or 404 — including when it exists but sits outside the caller's client scope. */
    private function reachable(Request $request, string $id): InfluencerCollaboration
    {
        $query = InfluencerCollaboration::query()
            ->with(['influencer', 'client', 'deliverables'])
            ->whereKey($id);

        $this->constrain($query, $request);

        $model = $query->first();
        abort_if($model === null, 404);

        return $model;
    }

    /**
     * The client ceiling. A collaboration with no client is agency-internal and stays visible; a
     * scoped caller sees those plus the ones for clients they hold.
     *
     * @param  Builder<InfluencerCollaboration>  $query
     */
    private function constrain($query, Request $request): void
    {
        $ids = $this->scopes->reachableClientIds($request->user());

        if ($ids === null) {
            return;
        }

        $query->where(function ($q) use ($ids): void {
            $q->whereNull('client_workspace_id')->orWhereIn('client_workspace_id', $ids);
        });
    }

    /** @return array<string, mixed> */
    private function present(InfluencerCollaboration $c, bool $canSeeCosts): array
    {
        $deliverables = $c->deliverables ?? collect();

        return array_merge([
            'id' => (string) $c->getKey(),
            'title' => $c->title,
            'status' => $c->status,
            'currency' => $c->currency,
            // Billed to the client — visible to anyone who may read collaborations, because it is
            // the number the client themselves will see on an invoice.
            'agreed_fee' => $c->agreed_fee === null ? null : (string) $c->agreed_fee,
            'starts_on' => $c->starts_on?->toDateString(),
            'ends_on' => $c->ends_on?->toDateString(),
            'brief' => $c->brief,
            'influencer' => $c->influencer === null ? null : [
                'id' => (string) $c->influencer->getKey(),
                'name' => $c->influencer->name,
                'handle' => $c->influencer->handle,
                'primary_platform' => $c->influencer->primary_platform,
            ],
            'client' => $c->client === null ? null : [
                'id' => (string) $c->client->getKey(),
                'name' => $c->client->name,
            ],
            'deliverables' => $deliverables->map(fn (InfluencerDeliverable $d) => [
                'id' => (string) $d->getKey(),
                'type' => $d->type,
                'platform' => $d->platform,
                'status' => $d->status,
                'due_on' => $d->due_on?->toDateString(),
                'submitted_url' => $d->submitted_url,
                'published_at' => $d->published_at?->toIso8601String(),
                'is_overdue' => $d->isOverdue(),
                'feedback' => $d->feedback,
            ])->values()->all(),
            // The progress line everyone asks for, which a single collaboration status cannot give.
            'progress' => [
                'total' => $deliverables->count(),
                'published' => $deliverables->where('status', 'published')->count(),
                'overdue' => $deliverables->filter(fn (InfluencerDeliverable $d) => $d->isOverdue())->count(),
            ],
        ], $canSeeCosts ? [
            'influencer_fee' => $c->influencer_fee === null ? null : (string) $c->influencer_fee,
            'margin' => $c->margin(),
            'internal_notes' => $c->internal_notes,
        ] : []);
    }
}
