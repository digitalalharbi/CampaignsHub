<?php

declare(strict_types=1);

namespace App\Domains\Taxonomy\Http\Controllers;

use App\Domains\Taxonomy\Services\PaidServiceCatalog;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PUBLIC, unauthenticated, read-only paid-media catalog for the anonymous marketing homepage and request
 * intake. It serves ONLY the platform-scope, active, is_public options of the `request.paid_service` definition
 * (via PaidServiceCatalog) — never any tenant/client/project data, never inactive/private options, never
 * another definition. Fail-closed. Rate-limited at the route. No writes, no auth, no tenant middleware.
 */
final class PublicPaidServiceController extends Controller
{
    public function __construct(private readonly PaidServiceCatalog $catalog) {}

    /** GET /api/v1/public/catalog/paid-media-services — engine-managed paid-media catalog (ETag + Cache-Control). */
    public function index(Request $request): JsonResponse
    {
        $catalog = $this->catalog->publicCatalog();
        $etag = '"'.$catalog['version'].'"';

        // Conditional GET: an unchanged catalog returns 304 without a body.
        $ifNoneMatch = $request->headers->get('If-None-Match');
        if ($ifNoneMatch !== null && trim($ifNoneMatch) === $etag) {
            return response()->json(null, 304)
                ->setEtag($catalog['version'])
                ->header('Cache-Control', 'public, max-age=300');
        }

        return ApiResponse::success($catalog, 'Paid-media service catalog.')
            ->setEtag($catalog['version'])
            ->header('Cache-Control', 'public, max-age=300');
    }
}
