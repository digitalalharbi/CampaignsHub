<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Http;

use App\Domains\Notifications\External\Review\ReviewQueue;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Review-before-send decisions (spec §9): list, «أرسل», «تخطَّ هذه المرة». Workspace-scoped; `settings.manage`. */
final class ExternalReviewsController extends Controller
{
    public function __construct(private readonly ReviewQueue $reviews, private readonly TenantContext $tenants) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->authorize($request);
        $status = in_array($request->query('status'), ['pending', 'sent', 'skipped'], true) ? (string) $request->query('status') : 'pending';

        return ApiResponse::success($this->reviews->list($tenant, $status));
    }

    public function send(Request $request, string $review): JsonResponse
    {
        $tenant = $this->authorize($request);
        $id = $this->reviews->send($tenant, $review, (int) $request->user()->getKey());

        return $id === null ? ApiResponse::error(null, ['review' => ['Not pending, expired, or not in this workspace.']], status: 422)
            : ApiResponse::success(['queued_delivery_id' => $id]);
    }

    public function skip(Request $request, string $review): JsonResponse
    {
        $tenant = $this->authorize($request);

        return $this->reviews->skip($tenant, $review, (int) $request->user()->getKey())
            ? ApiResponse::success(['skipped' => true])
            : ApiResponse::error(null, ['review' => ['Not pending or not in this workspace.']], status: 422);
    }

    private function authorize(Request $request): string
    {
        abort_unless($request->user()?->hasPermission('settings.manage'), 403);

        return (string) $this->tenants->tenantId();
    }
}
