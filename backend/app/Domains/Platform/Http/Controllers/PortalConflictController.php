<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Models\PortalIdentityConflict;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Resolving the identities the backfill refused to guess at (PORTAL-AUTH-001).
 *
 * The register exists because skipping silently strands people. This is where a human settles each
 * one, and the two answers are genuinely different decisions:
 *
 *   `link`      — the same person really does hold both roles. Grants the client-portal membership
 *                 ON TOP of what they already have. Both are then true, and both are audited.
 *   `separate`  — two different people share an address. Grants NOTHING; the contact needs their own
 *                 address before they can have a portal account, which is a conversation, not a
 *                 database operation.
 *
 * There is deliberately no "resolve all". The one conflict that occurs is a staff email, and
 * choosing wrong either gives an employee a client's view of their own agency or gives a client a
 * foothold on staff surfaces. That is not a decision to make in bulk.
 */
final class PortalConflictController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly GrantMembership $grants,
    ) {}

    /** GET /api/v1/admin/portal-conflicts */
    public function index(Request $request): JsonResponse
    {
        $this->tenants->enterPlatformScope();

        $query = PortalIdentityConflict::query()->orderByDesc('created_at');

        if ($request->boolean('open_only', true)) {
            $query->whereNull('resolution');
        }

        $rows = $query->limit(200)->get();
        $tenants = Tenant::query()->whereIn('id', $rows->pluck('tenant_id'))->pluck('name', 'id');

        return ApiResponse::success([
            'conflicts' => $rows->map(fn (PortalIdentityConflict $c) => [
                'id' => (string) $c->getKey(),
                'tenant_id' => (string) $c->tenant_id,
                'tenant_name' => $tenants[$c->tenant_id] ?? null,
                'contact_email' => $c->contact_email,
                'contact_phone' => $c->contact_phone,
                'reason' => $c->reason,
                'client_ids' => $c->client_ids ?? [],
                'resolution' => $c->resolution,
                'note' => $c->note,
                'resolved_at' => $c->resolved_at?->toIso8601String(),
            ])->all(),
            // The cutover gate, stated rather than left to be counted by eye.
            'open' => PortalIdentityConflict::query()->whereNull('resolution')->count(),
            'safe_to_retire_legacy_engine' => PortalIdentityConflict::query()->whereNull('resolution')->count() === 0,
        ], 'Portal identity conflicts.');
    }

    /**
     * PATCH /api/v1/admin/portal-conflicts/{conflict} — body: { resolution, note }.
     *
     * `link` is the only branch that grants anything, and it grants exactly the spaces recorded on
     * the conflict — never "everything this person can see", which is how a resolution turns into a
     * privilege escalation.
     */
    public function resolve(Request $request, string $conflict): JsonResponse
    {
        $this->tenants->enterPlatformScope();

        $data = $request->validate([
            'resolution' => ['required', Rule::in(['link', 'separate', 'dismiss'])],
            // Required for `link`, because granting a staff account a client's view needs a reason
            // somebody can read back later.
            'note' => ['required_if:resolution,link', 'nullable', 'string', 'max:500'],
        ]);

        /** @var PortalIdentityConflict|null $model */
        $model = PortalIdentityConflict::query()->whereKey($conflict)->first();
        abort_if($model === null, 404);
        abort_unless($model->isOpen(), 409, 'This conflict has already been resolved.');

        if ($data['resolution'] === 'link') {
            $this->link($model, $request->user());
        }

        $model->forceFill([
            'resolution' => $data['resolution'] === 'link' ? 'linked' : ($data['resolution'] === 'separate' ? 'separated' : 'dismissed'),
            'note' => $data['note'] ?? null,
            'resolved_by' => $request->user()?->getKey(),
            'resolved_at' => now(),
        ])->save();

        AuditLog::create([
            'tenant_id' => $model->tenant_id,
            'user_id' => $request->user()?->getKey(),
            'action' => 'platform.portal_conflict.resolved',
            'entity_type' => PortalIdentityConflict::class,
            'entity_id' => (string) $model->getKey(),
            'before' => ['resolution' => null],
            'after' => ['resolution' => $model->resolution, 'client_ids' => $model->client_ids],
            'reason' => $model->note,
            'ip_address' => $request->ip(),
        ]);

        return ApiResponse::success([
            'conflict' => ['id' => (string) $model->getKey(), 'resolution' => $model->resolution],
        ], 'Conflict resolved.');
    }

    /** The same person, both roles. Additive: what they already hold is untouched. */
    private function link(PortalIdentityConflict $conflict, ?User $actor): void
    {
        $user = User::query()->where('email', $conflict->contact_email)->first();
        abort_if($user === null, 422, 'No account holds that address any more.');

        // The platform owner belongs to no tenant, so a client-portal membership would place them
        // inside one they administer. Refused outright rather than resolved.
        abort_if($user->is_platform_admin, 422, 'The platform owner cannot also be a portal client.');

        $tenant = Tenant::query()->whereKey($conflict->tenant_id)->firstOrFail();

        $this->grants->execute(new MembershipGrant(
            user: $user,
            tenant: $tenant,
            portal: Portal::ClientPortal,
            role: 'client_viewer',
            // Exactly the spaces the conflict recorded — never a wider set.
            clientScopeIds: $conflict->client_ids ?? [],
            grantedBy: $actor,
        ));
    }
}
