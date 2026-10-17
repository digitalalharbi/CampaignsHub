<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Http\Controllers\Internal;

use App\Domains\ClientWorkspaces\Services\ClientAccess;
use App\Domains\ClientWorkspaces\Services\ClientPortalUsers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CP-USERS-001 — the agency gives a client's people their portal, one client at a time.
 *
 * `clients.manage_team` AND reach to THIS client: an operator narrowed to some clients cannot add a person to
 * another client's portal by guessing its id.
 */
final class ClientPortalUsersController
{
    public function __construct(private readonly ClientAccess $access, private readonly ClientPortalUsers $users) {}

    /** GET /app/clients/{client}/portal-users */
    public function index(Request $request, string $client): JsonResponse
    {
        $workspace = $this->access->resolve($client);
        $this->access->assert($request->user(), 'clients.manage_team', $workspace);

        return response()->json(['data' => ['users' => $this->users->list($workspace)]]);
    }

    /** POST /app/clients/{client}/portal-users — grant an existing account, or invite a new one. */
    public function store(Request $request, string $client): JsonResponse
    {
        $workspace = $this->access->resolve($client);
        $this->access->assert($request->user(), 'clients.manage_team', $workspace);

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'role' => ['required', Rule::in(ClientPortalUsers::ROLES)],
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => ['uuid'],
        ]);

        $result = $this->users->grantOrInvite($workspace, $data['email'], $data['role'], $data['project_ids'] ?? null, $request->user());

        return response()->json(['data' => $result], $result['state'] === 'granted' ? 200 : 201);
    }

    /** DELETE /app/clients/{client}/portal-users/{membership} */
    public function destroy(Request $request, string $client, string $membership): JsonResponse
    {
        $workspace = $this->access->resolve($client);
        $this->access->assert($request->user(), 'clients.manage_team', $workspace);
        $this->users->revoke($workspace, $membership);

        return response()->json(['data' => ['revoked' => true]]);
    }
}
