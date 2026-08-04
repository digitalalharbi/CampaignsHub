<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Accounts\Models\AccountGrant;
use App\Domains\Accounts\Services\AccountGrants;
use App\Domains\Subscriptions\Services\PlanCatalogue;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The console's half of GRANT-001 — giving one account an exception, and taking it back.
 *
 * Everything here is behind the `platform` middleware, which is what makes "a user cannot grant
 * themselves permissions" structural rather than a rule somebody has to remember: a tenant user has
 * no route to this controller at all, whatever their role inside their own workspace.
 *
 * There is deliberately no endpoint that grants something to MANY accounts, and none that edits a
 * grant in place. An exception is per account and is either made or withdrawn; editing one would
 * quietly rewrite what was agreed while keeping the original reason attached to it.
 */
final class PlatformGrantController extends Controller
{
    public function __construct(
        private readonly AccountGrants $grants,
        private readonly PlanCatalogue $catalogue,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * GET /admin/tenants/{tenant}/grants
     *
     * The whole history, not just what is live: the revoked rows are the audit, and hiding them would
     * make "this account used to have that" unanswerable.
     */
    public function index(string $tenant): JsonResponse
    {
        $this->tenants->enterPlatformScope();

        $model = Tenant::withoutGlobalScopes()->findOrFail($tenant);

        return ApiResponse::success([
            'grants' => $this->grants->history($model)
                ->map(fn (AccountGrant $g) => $this->grants->toArray($g))->values()->all(),
            // What may be granted, so the console offers real options rather than a free-text box
            // whose typos become grants that silently do nothing.
            'catalogue' => $this->catalogue(),
        ], 'Account grants.');
    }

    /** POST /admin/tenants/{tenant}/grants */
    public function store(Request $request, string $tenant): JsonResponse
    {
        $this->tenants->enterPlatformScope();

        $model = Tenant::withoutGlobalScopes()->findOrFail($tenant);

        $data = $request->validate([
            'kind' => ['required', Rule::in(AccountGrant::kinds())],
            /*
             * The value is checked against the catalogue below rather than merely being a string.
             *
             * A grant naming a section no portal offers, or a plan that does not exist, is not a
             * dangerous grant — the entitlement engine intersects it away — but it is a WORSE one:
             * it looks like access was given, and the customer still cannot do the thing.
             */
            'value' => ['required_unless:kind,full_access', 'nullable', 'string', 'max:64'],
            // Not optional, and not defaulted to something bland. The brief requires a recorded
            // reason, and «تم المنح» in a log is the same as no reason at all.
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            // Optional expiry — how a concession is given for a quarter rather than forever.
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        $kind = (string) $data['kind'];
        $value = (string) ($data['value'] ?? '');

        if (! $this->isGrantable($kind, $value)) {
            return ApiResponse::error(
                'That is not something this platform can grant.',
                ['value' => ['Unknown '.$kind.' ['.$value.'].']],
                status: 422,
            );
        }

        $grant = $this->grants->grant(
            tenant: $model,
            kind: $kind,
            value: $value,
            reason: (string) $data['reason'],
            actorId: $request->user()?->getKey(),
            expiresAt: isset($data['expires_at']) && $data['expires_at'] !== null
                ? new \DateTimeImmutable((string) $data['expires_at'])
                : null,
        );

        return ApiResponse::success(['grant' => $this->grants->toArray($grant)], 'Granted.', status: 201);
    }

    /**
     * DELETE /admin/tenants/{tenant}/grants/{grant}
     *
     * A revocation, not a deletion. The row stays and records who took it back and why — and it takes
     * effect on the very next request, because `AccountEntitlements` reads grants in force rather
     * than a copy of them written into the tenant.
     */
    public function destroy(Request $request, string $tenant, string $grant): JsonResponse
    {
        $this->tenants->enterPlatformScope();

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        $model = AccountGrant::query()->where('tenant_id', $tenant)->whereKey($grant)->firstOrFail();

        $revoked = $this->grants->revoke($model, (string) $data['reason'], $request->user()?->getKey());

        return ApiResponse::success(['grant' => $this->grants->toArray($revoked)], 'Revoked.');
    }

    /**
     * Everything that may legitimately be named in a grant.
     *
     * @return array{sections: list<string>, modules: list<string>, plans: list<string>}
     */
    private function catalogue(): array
    {
        $sections = collect(Portal::cases())
            // The owner's console is not a thing an account can be granted a piece of: it is held by
            // a flag, not by an entitlement, and listing its sections here would suggest otherwise.
            ->reject(fn (Portal $p) => $p === Portal::Admin)
            ->flatMap(fn (Portal $p) => $p->sections())
            ->unique()->sort()->values()->all();

        return [
            'sections' => $sections,
            'modules' => ['paid_media', 'influencer_marketing'],
            'plans' => $this->catalogue->all()->pluck('code')->values()->all(),
        ];
    }

    private function isGrantable(string $kind, string $value): bool
    {
        $catalogue = $this->catalogue();

        return match ($kind) {
            AccountGrant::FULL_ACCESS => true,
            AccountGrant::SECTION => in_array($value, $catalogue['sections'], true),
            AccountGrant::MODULE => in_array($value, $catalogue['modules'], true),
            AccountGrant::PLAN => in_array($value, $catalogue['plans'], true),
            default => false,
        };
    }
}
