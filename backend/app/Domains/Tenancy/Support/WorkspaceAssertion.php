<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Support;

use App\Domains\Tenancy\Models\Membership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ENSURE-PORTAL-SESSION-001 — the workspace a tab believes it is in, stated on its requests and checked here.
 *
 * Every tab of a browser shares one session, so a tab that missed the signal of a switch made elsewhere still draws the
 * old workspace while its requests run in the new one. It would show tenant B's answers under tenant A's name, and its
 * writes would land in tenant B. The header closes that: a tab names the membership its screen was drawn for, and a
 * request whose claim is not the membership the server resolved is refused.
 *
 * It can only ever REFUSE. It selects nothing and is never read to decide a scope, so a forged value gains nothing: the
 * membership still comes from the session, re-verified against the database, and the answer to a wrong claim is 409.
 * No header means no claim. Token clients and plain links send none, and are held by their own URL and scope checks.
 *
 * `auth/*` is exempt, because a tab told to leave has to be able to read the list, make the switch, and sign out.
 */
final class WorkspaceAssertion
{
    public const HEADER = 'X-Workspace-Membership';

    public const CODE = 'workspace_changed';

    public static function claimed(Request $request): bool
    {
        return (string) $request->header(self::HEADER, '') !== '';
    }

    public static function refuses(Request $request, Membership $resolved): bool
    {
        return self::claimed($request)
            && ! $request->is('api/v1/auth/*')
            && (string) $request->header(self::HEADER) !== (string) $resolved->getKey();
    }

    public static function refusal(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => __('api.workspace_changed'), 'code' => self::CODE], 409);
    }
}
