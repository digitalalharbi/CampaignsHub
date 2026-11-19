<?php

declare(strict_types=1);

namespace App\Domains\Agents\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Tenancy\Context\MembershipContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * MCP-SERVER-001 — the person's own access tokens for connecting Claude, Gemini or ChatGPT to CampaignsHub over MCP.
 *
 * A token acts as the person who created it, inside their workspace and permissions, and is shown ONCE when created (only its
 * hash is stored). Each is named, listed with when it was last used, and revocable. At most ten per person.
 */
final class McpTokenController extends Controller
{
    private const PREFIX = 'mcp:';

    private const MAX = 10;

    public function __construct(private readonly AuditLogger $audit) {}

    /** GET /api/v1/me/mcp-tokens */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(['tokens' => $this->list($this->user($request))], 'MCP tokens.');
    }

    /** POST /api/v1/me/mcp-tokens — {name} → the token, once */
    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:60'], 'read_only' => ['sometimes', 'boolean']]);
        // MCP-ANALYTICS-001 — least privilege: read-only unless the person explicitly asks for the proposal tool as well.
        $readOnly = (bool) ($data['read_only'] ?? true);
        abort_if($user->tokens()->where('name', 'like', self::PREFIX.'%')->count() >= self::MAX, 422, 'too_many_tokens');

        // MCP-SCOPE-001 — pinned to the workspace it was made in; it answers from that workspace alone.
        $membership = app(MembershipContext::class)->membership()?->getKey();
        $token = $user->createToken(
            self::PREFIX.trim($data['name']),
            ['mcp', ...($readOnly ? ['mcp:read'] : []), ...($membership === null ? [] : ['membership:'.$membership])],
        );
        $this->audit->log(action: 'mcp.token.created', entityType: PersonalAccessToken::class, entityId: (string) $token->accessToken->getKey(), after: ['name' => trim($data['name']), 'read_only' => $readOnly]);

        return ApiResponse::success(['token' => $token->plainTextToken, 'tokens' => $this->list($user)], 'Created.', status: 201);
    }

    /** DELETE /api/v1/me/mcp-tokens/{id} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);
        $row = $user->tokens()->where('name', 'like', self::PREFIX.'%')->whereKey($id)->firstOrFail();
        $row->delete();
        $this->audit->log(action: 'mcp.token.revoked', entityType: PersonalAccessToken::class, entityId: $id);

        return ApiResponse::success(['tokens' => $this->list($user)], 'Revoked.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** @return list<array<string, mixed>> */
    private function list(User $user): array
    {
        return $user->tokens()->where('name', 'like', self::PREFIX.'%')->orderByDesc('id')->get()
            ->map(static fn (PersonalAccessToken $t): array => [
                'id' => (string) $t->getKey(),
                'name' => substr((string) $t->name, strlen(self::PREFIX)),
                'created_at' => $t->created_at?->toIso8601String(),
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'read_only' => in_array('mcp:read', (array) $t->abilities, true),
            ])->values()->all();
    }
}
