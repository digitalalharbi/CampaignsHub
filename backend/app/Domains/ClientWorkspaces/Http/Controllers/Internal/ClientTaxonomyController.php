<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Http\Controllers\Internal;

use App\Domains\ClientWorkspaces\Enums\ClientAccessRole;
use App\Domains\ClientWorkspaces\Enums\ClientStatus;
use App\Domains\ClientWorkspaces\Enums\Industry;
use App\Domains\ClientWorkspaces\Enums\ServiceLevel;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Enum catalogue + assignable owners for classification/settings/team dropdowns. */
final class ClientTaxonomyController
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('clients.view'), 403);

        /*
         * Who may be assigned, resolved through MEMBERSHIPS (ADR 0002).
         *
         * This read `users.tenant_id` — a column dropped in `2f88246` — so every call to it was a
         * 500 and the classification, settings and team dropdowns that depend on it were empty. The
         * guard test for that column only looked for a property READ on a user model, not for a
         * QUERY against the column, which is how a whole endpoint stayed broken behind a green suite.
         *
         * Membership is the scope source now: the people who may own a client are the people who
         * hold an active membership in this workspace.
         */
        $owners = User::query()
            ->whereIn('id', Membership::query()
                ->where('tenant_id', (string) $this->tenant->tenantId())
                ->active()
                ->select('user_id'))
            ->orderBy('name')->get(['id', 'name', 'email'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
            ->values();

        return response()->json(['data' => [
            'client_statuses' => ClientStatus::values(),
            'service_levels' => ServiceLevel::values(),
            'industries' => Industry::values(),
            'access_roles' => ClientAccessRole::values(),
            'priorities' => ['low', 'normal', 'high'],
            'assignable_users' => $owners,
        ]]);
    }
}
