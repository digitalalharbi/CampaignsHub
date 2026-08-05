<?php

declare(strict_types=1);

namespace App\Domains\Legal\Http\Controllers;

use App\Domains\Legal\Models\PlatformSetting;
use App\Domains\Legal\PolicyRegistry;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * LEGAL-001 — the operator's identity and policy versions, readable without a session.
 *
 * Public on purpose, and that is a requirement rather than a convenience: every platform whose OAuth
 * review this product has to pass (Google, Meta, TikTok, Snapchat, LinkedIn, X, Salla, Zid) fetches
 * the privacy policy and terms URLs itself, unauthenticated, from the same domain as the app. A
 * policy page that needed a login would fail review without anybody being told why.
 *
 * It exposes only what a policy page prints. There is no write here — the operator's details are
 * edited from `/admin`, behind the platform gate.
 */
final class PublicLegalController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success([
            'operator' => PlatformSetting::current()->toPublicArray(),
            'documents' => PolicyRegistry::all(),
            /*
             * The documents a visitor must accept to register or pay, named here rather than
             * hard-coded in the client — so adding one is a single edit that the registration and
             * payment forms pick up without being touched.
             */
            'binding' => PolicyRegistry::binding(),
        ], 'Legal metadata.');
    }
}
