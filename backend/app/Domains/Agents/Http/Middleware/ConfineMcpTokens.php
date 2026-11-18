<?php

declare(strict_types=1);

namespace App\Domains\Agents\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCP-SCOPE-001 — a connector token (Claude, ChatGPT, Cursor…) opens the MCP door and no other.
 *
 * Its whole promise to the person is «reads as you, proposes changes that wait for your approval». A token that
 * could also call the REST API would break that promise: it could change a budget directly. So a token whose
 * abilities are `mcp` (and not `*`) is answered 403 everywhere except `/api/v1/mcp`.
 *
 * Two more refusals:
 *   - platform staff never get a connector session: their requests run in platform scope, across every tenant.
 *   - a token pinned to a workspace (`membership:<id>`) is resolved to that workspace only — see ResolveMembership.
 *
 * Appended to the api group, so it runs before route middleware; the token is read straight from Sanctum's guard.
 */
final class ConfineMcpTokens
{
    private const DOOR = 'api/v1/mcp';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() === null) {
            return $next($request);
        }

        $user = Auth::guard('sanctum')->user();
        if (! $user instanceof User || ! self::isConnectorToken($user)) {
            return $next($request);
        }

        if ($user->is_platform_admin) {
            return response()->json(['message' => 'A connector cannot act as platform staff.'], 403);
        }

        if (trim($request->path(), '/') !== self::DOOR) {
            return response()->json(['message' => 'This token only opens the MCP server.'], 403);
        }

        return $next($request);
    }

    /** A Sanctum personal token that carries `mcp` and not the all-abilities `*`. */
    public static function isConnectorToken(User $user): bool
    {
        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return false;
        }
        $abilities = (array) $token->abilities;

        return in_array('mcp', $abilities, true) && ! in_array('*', $abilities, true);
    }

    /** The membership a connector token was approved in, when it was pinned to one. */
    public static function pinnedMembership(User $user): ?string
    {
        if (! self::isConnectorToken($user)) {
            return null;
        }
        /** @var PersonalAccessToken $token */
        $token = $user->currentAccessToken();
        foreach ((array) $token->abilities as $ability) {
            if (is_string($ability) && str_starts_with($ability, 'membership:')) {
                return substr($ability, strlen('membership:'));
            }
        }

        return null;
    }
}
