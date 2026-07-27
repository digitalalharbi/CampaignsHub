<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Resources\UserResource;
use App\Domains\Identity\Services\InvitationService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Workspace invitations — invite/list (authorized) + public preview/accept. */
final class InvitationController extends Controller
{
    public function __construct(
        private readonly InvitationService $service,
        private readonly TenantContext $context,
    ) {}

    /** POST /app/team/invitations — invite a member to this workspace. */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('users.invite'), 403);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'role_slug' => ['required', 'string', 'max:60'],
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => ['string'],
        ]);
        $tenant = Tenant::findOrFail((string) $this->context->tenantId());
        $result = $this->service->invite($tenant, $data['email'], $data['role_slug'], $data['project_ids'] ?? null, $request->user());

        return ApiResponse::success($result, 'Invitation created.', status: 201);
    }

    /** GET /app/team/invitations — pending invites for this workspace. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('users.invite'), 403);
        $rows = DB::table('workspace_invitations')->where('tenant_id', (string) $this->context->tenantId())
            ->orderByDesc('created_at')->limit(100)
            ->get(['id', 'email', 'role_slug', 'accepted_at', 'expires_at', 'created_at'])
            ->map(fn ($r) => [
                'id' => $r->id, 'email' => $r->email, 'role_slug' => $r->role_slug,
                'status' => $r->accepted_at ? 'accepted' : 'pending',
                'expires_at' => $r->expires_at, 'created_at' => $r->created_at,
            ]);

        return ApiResponse::success(['invitations' => $rows], 'Invitations retrieved.');
    }

    /** GET /invitations/{token} — public preview of an invitation. */
    public function preview(string $token): JsonResponse
    {
        return ApiResponse::success($this->service->preview($token), 'Invitation preview.');
    }

    /** POST /invitations/accept — accept, creating the user in the inviting workspace and signing them in. */
    public function accept(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'password' => ['required', 'string', 'min:8'],
        ]);
        $user = $this->service->accept($data['token'], $data['name'], $data['password']);

        // Sign the new member in on the SPA cookie session (only when a session is present).
        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        return ApiResponse::success(['user' => new UserResource($user->load('roles', 'tenant'))], 'Invitation accepted.', status: 201);
    }
}
