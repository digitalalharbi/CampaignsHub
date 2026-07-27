<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Http\Controllers\Internal;

use App\Domains\ClientWorkspaces\Services\ClientAccess;
use App\Domains\ClientWorkspaces\Services\ClientTeamService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Per-client team access tab — list/grant/change/remove, all permission + access gated, backend-enforced. */
final class ClientTeamController
{
    public function __construct(
        private readonly ClientAccess $access,
        private readonly ClientTeamService $team,
    ) {}

    /** GET /app/clients/{client}/team — members who have access to THIS client. */
    public function index(Request $request, string $client): JsonResponse
    {
        $c = $this->access->resolve($client);
        $this->access->assert($request->user(), 'clients.manage_team', $c);

        $rows = DB::table('client_workspace_user as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.client_workspace_id', $c->id)
            ->select('u.id', 'u.name', 'u.email', 'm.access_role', 'm.project_ids', 'm.granted_at')
            ->orderBy('u.name')->get()
            ->map(fn ($r) => [
                'user_id' => $r->id,
                'name' => $r->name,
                'email' => $r->email,
                'access_role' => $r->access_role,
                'project_ids' => $r->project_ids ? json_decode($r->project_ids, true) : null,
                'granted_at' => $r->granted_at,
            ]);

        return response()->json(['data' => ['members' => $rows]]);
    }

    /** POST /app/clients/{client}/team — grant access to a same-tenant user. */
    public function store(Request $request, string $client): JsonResponse
    {
        $c = $this->access->resolve($client);
        $this->access->assert($request->user(), 'clients.manage_team', $c);

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'access_role' => ['required', 'string'],
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => ['string'],
        ]);

        $this->team->grant($c, (int) $data['user_id'], $data['access_role'], $data['project_ids'] ?? null, $request->user());

        return response()->json(['data' => ['granted' => true]], 201);
    }

    /** PATCH /app/clients/{client}/team/{user} — change role / project restriction. */
    public function update(Request $request, string $client, int $user): JsonResponse
    {
        $c = $this->access->resolve($client);
        $this->access->assert($request->user(), 'clients.manage_team', $c);

        $data = $request->validate([
            'access_role' => ['sometimes', 'string'],
            'project_ids' => ['sometimes', 'nullable', 'array'],
            'project_ids.*' => ['string'],
        ]);

        $this->team->updateAccess($c, $user, $data['access_role'] ?? null, $data['project_ids'] ?? null, $request->user());

        return response()->json(['data' => ['updated' => true]]);
    }

    /** DELETE /app/clients/{client}/team/{user} — revoke access (denies the API immediately). */
    public function destroy(Request $request, string $client, int $user): JsonResponse
    {
        $c = $this->access->resolve($client);
        $this->access->assert($request->user(), 'clients.manage_team', $c);

        $this->team->remove($c, $user, $request->user());

        return response()->json(['data' => ['removed' => true]]);
    }

    /** GET /app/clients/{client}/team/assignable — same-tenant users that can be granted access. */
    public function assignable(Request $request, string $client): JsonResponse
    {
        $c = $this->access->resolve($client);
        $this->access->assert($request->user(), 'clients.manage_team', $c);

        $existing = DB::table('client_workspace_user')->where('client_workspace_id', $c->id)->pluck('user_id');
        $users = User::where('tenant_id', $c->tenant_id)->whereNotIn('id', $existing)
            ->orderBy('name')->get(['id', 'name', 'email'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email]);

        return response()->json(['data' => ['assignable' => $users]]);
    }
}
