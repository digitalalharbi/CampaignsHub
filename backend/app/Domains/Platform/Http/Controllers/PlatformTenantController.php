<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tenants, from the platform owner's side (ADMIN-001).
 *
 * The only surface in the product that lists tenants. It shows what the owner needs to run the
 * business — who exists, on what plan, active or suspended, how large — and deliberately not what
 * they are doing: no campaign names, no client names, no figures. Owning the platform is not a
 * reason to read a customer's work, and a console that made it effortless would see it happen
 * without anyone deciding to.
 *
 * Suspending is the one write here, and it is the reason the console exists at all: today a tenant
 * can only be suspended by editing the database by hand.
 */
final class PlatformTenantController extends Controller
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** GET /api/v1/admin/tenants */
    public function index(Request $request): JsonResponse
    {
        $this->tenants->enterPlatformScope();

        $query = Tenant::query()->orderBy('name');

        if (($status = $request->query('status')) !== null && $status !== '') {
            $query->where('status', $status);
        }
        if (($term = trim((string) $request->query('q', ''))) !== '') {
            $query->where(function ($q) use ($term): void {
                $q->whereRaw('lower(name) like ?', ['%'.mb_strtolower($term).'%'])
                    ->orWhereRaw('lower(slug) like ?', ['%'.mb_strtolower($term).'%']);
            });
        }

        $page = $query->paginate(25);

        // Counted in two queries rather than per row, so a hundred tenants is not a hundred round trips.
        $ids = collect($page->items())->map(fn (Tenant $t) => $t->getKey())->all();
        $people = Membership::query()->whereIn('tenant_id', $ids)
            ->selectRaw('tenant_id, count(distinct user_id) as c')->groupBy('tenant_id')->pluck('c', 'tenant_id');
        $clients = ClientWorkspace::query()->whereIn('tenant_id', $ids)->whereNull('archived_at')
            ->selectRaw('tenant_id, count(*) as c')->groupBy('tenant_id')->pluck('c', 'tenant_id');

        return ApiResponse::success([
            'tenants' => collect($page->items())->map(fn (Tenant $t) => [
                'id' => (string) $t->getKey(),
                'name' => $t->name,
                'slug' => $t->slug,
                'status' => $t->status,
                'account_type' => $t->account_type,
                'subscription_plan' => $t->subscription_plan,
                'onboarding_completed' => $t->onboarding_completed_at !== null,
                'people' => (int) ($people[$t->getKey()] ?? 0),
                'client_workspaces' => (int) ($clients[$t->getKey()] ?? 0),
                'created_at' => $t->created_at?->toIso8601String(),
            ])->all(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage()],
        ], 'Tenants.');
    }

    /**
     * PATCH /api/v1/admin/tenants/{tenant}/status — body: { status, reason }.
     *
     * Suspending locks every person whose ONLY workspace is this one out of the product, so it is
     * recorded with a reason and an actor. `AccountSuspension` already makes the per-person decision
     * correctly: someone who also belongs elsewhere keeps that access.
     */
    public function updateStatus(Request $request, string $tenant): JsonResponse
    {
        $this->tenants->enterPlatformScope();

        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended'])],
            'reason' => ['required_if:status,suspended', 'nullable', 'string', 'max:500'],
        ]);

        /** @var Tenant|null $model */
        $model = Tenant::query()->whereKey($tenant)->first();
        abort_if($model === null, 404);

        // The owner cannot suspend the workspace they are signed in through — but they belong to
        // none, so the real hazard is different: suspending the tenant that serves the public
        // request portal takes the intake form down for everyone.
        $wasPublicPortal = (bool) $model->is_default_portal;

        $before = $model->status;
        $model->forceFill(['status' => $data['status']])->save();

        AuditLog::create([
            'tenant_id' => $model->getKey(),
            'user_id' => $request->user()?->getKey(),
            'action' => 'platform.tenant.status_changed',
            'entity_type' => Tenant::class,
            'entity_id' => (string) $model->getKey(),
            'before' => ['status' => $before],
            'after' => ['status' => $data['status']],
            'reason' => $data['reason'] ?? null,
            'ip_address' => $request->ip(),
        ]);

        return ApiResponse::success([
            'tenant' => ['id' => (string) $model->getKey(), 'status' => $model->status],
            // Said plainly rather than blocked: the owner may genuinely need to do this, but should
            // not discover afterwards that public intake stopped.
            'public_intake_affected' => $wasPublicPortal && $data['status'] === 'suspended',
        ], 'Tenant status updated.');
    }

    /**
     * GET /api/v1/admin/tenants/{tenant} — one tenant's shape, still without its work.
     */
    public function show(string $tenant): JsonResponse
    {
        $this->tenants->enterPlatformScope();

        /** @var Tenant|null $model */
        $model = Tenant::query()->whereKey($tenant)->first();
        abort_if($model === null, 404);

        $memberships = Membership::query()->where('tenant_id', $model->getKey())->with('user')->get();

        return ApiResponse::success([
            'tenant' => [
                'id' => (string) $model->getKey(),
                'name' => $model->name,
                'slug' => $model->slug,
                'status' => $model->status,
                'account_type' => $model->account_type,
                'subscription_plan' => $model->subscription_plan,
                'onboarding_completed' => $model->onboarding_completed_at !== null,
                'is_default_portal' => (bool) $model->is_default_portal,
                'created_at' => $model->created_at?->toIso8601String(),
            ],
            // Who can get in, and through which portal. The owner's job is access, not content.
            'people' => $memberships->map(fn (Membership $m) => [
                'user_id' => (string) $m->user_id,
                'name' => $m->user?->name,
                'email' => $m->user?->email,
                'portal' => $m->portal->value,
                'role' => $m->role,
                'status' => $m->status,
            ])->values()->all(),
            'client_workspaces' => ClientWorkspace::query()
                ->where('tenant_id', $model->getKey())->whereNull('archived_at')->count(),
        ], 'Tenant.');
    }

    /**
     * The four categories OPS-002 names, as filters (OPS-002).
     *
     * The requirement is «an audit trail for every subscription change, payment, approval decision and
     * permission grant». A trail with no way to ask those four questions satisfies it only on paper:
     * the platform log runs to thousands of rows and `user.login` alone is over half of them, so the
     * entries that matter are unfindable by scrolling.
     *
     * Prefix matching against the action name, so a new `subscription.*` or `payment.*` action written
     * later is covered without anyone updating a list — which is the same reason the audit itself is an
     * observer rather than a call at each site.
     *
     * @var array<string, list<string>>
     */
    private const CATEGORIES = [
        'subscriptions' => ['subscription.', 'plan.'],
        'payments' => ['payment.'],
        'approvals' => ['registration.', 'account.state.', 'request.status_changed', 'request.converted'],
        'permissions' => ['settings.team.', 'project.member.', 'client.team_access', 'access.'],
    ];

    /**
     * GET /api/v1/admin/audit — the platform's own trail, newest first.
     *
     * Entries carry the actor's and workspace's NAMES, not only their ids. A trail that answers «who»
     * with a UUID answers nobody: the reader has to go and look it up somewhere else, which in practice
     * means the question goes unanswered. Resolved in two queries over the page, never per row.
     */
    public function audit(Request $request): JsonResponse
    {
        $this->tenants->enterPlatformScope();

        $category = (string) $request->query('category', '');
        $prefixes = self::CATEGORIES[$category] ?? null;

        $page = AuditLog::query()
            ->when($request->query('action'), fn ($q, $a) => $q->where('action', 'like', $a.'%'))
            ->when($prefixes !== null, fn ($q) => $q->where(function ($inner) use ($prefixes) {
                foreach ($prefixes as $prefix) {
                    $inner->orWhere('action', 'like', $prefix.'%');
                }
            }))
            ->orderByDesc('created_at')
            ->paginate(50);

        $entries = collect($page->items());
        $users = User::query()->whereIn('id', $entries->pluck('user_id')->filter()->unique())->pluck('name', 'id');
        $tenants = Tenant::query()->whereIn('id', $entries->pluck('tenant_id')->filter()->unique())->pluck('name', 'id');

        return ApiResponse::success([
            'entries' => $entries->map(fn (AuditLog $l) => [
                'id' => (string) $l->getKey(),
                'action' => $l->action,
                'category' => $this->categoryOf($l->action),
                'tenant_id' => $l->tenant_id === null ? null : (string) $l->tenant_id,
                // Null when the actor or workspace has since been deleted. Left null rather than
                // filled with «Unknown», which reads as a name and is not one.
                'tenant_name' => $l->tenant_id === null ? null : ($tenants[$l->tenant_id] ?? null),
                'user_id' => $l->user_id === null ? null : (string) $l->user_id,
                'user_name' => $l->user_id === null ? null : ($users[$l->user_id] ?? null),
                'before' => $l->before,
                'after' => $l->after,
                'reason' => $l->reason,
                'created_at' => $l->created_at?->toIso8601String(),
            ])->all(),
            'categories' => array_keys(self::CATEGORIES),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage()],
        ], 'Audit trail.');
    }

    /** Which of the four an action belongs to, or null for everything else. */
    private function categoryOf(?string $action): ?string
    {
        if ($action === null) {
            return null;
        }

        foreach (self::CATEGORIES as $name => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($action, $prefix)) {
                    return $name;
                }
            }
        }

        return null;
    }
}
