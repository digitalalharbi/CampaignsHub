<?php

declare(strict_types=1);

namespace App\Domains\Agents\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCP-OAUTH-001 — a refused MCP request says where to sign in.
 *
 * Claude and ChatGPT find the sign-in from the 401 itself: `WWW-Authenticate: Bearer resource_metadata="…"` names the
 * protected-resource document, which names the authorization server. Without it they have no way to start «سماح».
 *
 * It answers the refusal ITSELF, before `auth:sanctum`: that middleware throws, and the exception is rendered above the
 * route pipeline, so a header added on the way back out would never be seen. A request that does carry a person goes on
 * to `auth:sanctum` unchanged, which remains the gate.
 */
final class McpAuthChallenge
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('sanctum')->user() === null) {
            $base = rtrim((string) config('services.mcp.url', 'https://mcp.campaignshub.io'), '/');

            return response()->json(['message' => 'Unauthenticated.'], 401)
                ->header('WWW-Authenticate', 'Bearer resource_metadata="'.$base.'/.well-known/oauth-protected-resource"');
        }

        return $next($request);
    }
}
