<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Lists the current tenant's users (for team/member pickers). Tenant-isolated. */
final class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('users.view'), 403);

        $tenantId = app(TenantContext::class)->tenantId();
        $users = User::query()
            ->inTenant($tenantId)
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'email'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'uuid' => $u->uuid,
                'name' => $u->name,
                'email' => $u->email,
            ]);

        return ApiResponse::success($users, 'Users retrieved.');
    }
}
