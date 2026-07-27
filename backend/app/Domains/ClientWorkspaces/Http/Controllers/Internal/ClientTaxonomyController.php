<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Http\Controllers\Internal;

use App\Domains\ClientWorkspaces\Enums\ClientAccessRole;
use App\Domains\ClientWorkspaces\Enums\ClientStatus;
use App\Domains\ClientWorkspaces\Enums\Industry;
use App\Domains\ClientWorkspaces\Enums\ServiceLevel;
use App\Domains\Tenancy\Context\TenantContext;
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

        $owners = User::query()
            ->where('tenant_id', (string) $this->tenant->tenantId())
            ->orderBy('name')->get(['id', 'name', 'email'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email]);

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
