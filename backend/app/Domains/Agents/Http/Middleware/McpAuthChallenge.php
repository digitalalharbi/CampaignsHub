<?php

declare(strict_types=1);

namespace App\Domains\Agents\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCP-OAUTH-001 — a refused MCP request says where to sign in.
 *
 * Claude and ChatGPT find the sign-in from the 401 itself: `WWW-Authenticate: Bearer resource_metadata="…"` names the
 * protected-resource document, which names the authorization server. Without it they have no way to start «سماح».
 * Sits outside `auth:sanctum`, so it sees the 401 that middleware answered with.
 */
final class McpAuthChallenge
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() === 401) {
            $base = rtrim((string) config('services.mcp.url', 'https://mcp.campaignshub.io'), '/');
            $response->headers->set('WWW-Authenticate', 'Bearer resource_metadata="'.$base.'/.well-known/oauth-protected-resource"');
        }

        return $response;
    }
}
