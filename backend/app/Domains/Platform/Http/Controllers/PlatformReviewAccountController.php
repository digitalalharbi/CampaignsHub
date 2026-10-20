<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Support\ReviewAccess;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * SALLA-REVIEW-001 — the platform owner's one-click revoke and extend for review workspaces.
 *
 * Platform-admin only (the route group enforces it). Both are a change to two fields on the tenant and take
 * effect on the reviewer's very next request; both are audited. They act only on a tenant that IS a review
 * workspace, so neither can be pointed at a customer.
 */
final class PlatformReviewAccountController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = Tenant::query()->get()->filter(fn (Tenant $t) => ReviewAccess::settingsOf($t) !== null)->map(fn (Tenant $t) => $this->shape($t))->values();

        return ApiResponse::success($rows, 'Review workspaces.');
    }

    public function extend(Request $request, AuditLogger $audit, string $tenant): JsonResponse
    {
        $days = (int) $request->validate(['days' => ['required', 'integer', 'min:1', 'max:90']])['days'];
        $model = $this->reviewTenant($tenant);
        $review = (array) (($model->settings ?? [])['review'] ?? []);
        $from = isset($review['expires_at']) && Carbon::parse((string) $review['expires_at'])->isFuture() ? Carbon::parse((string) $review['expires_at']) : Carbon::now();
        $this->save($model, ['active' => true, 'expires_at' => $from->addDays($days)->toIso8601String()]);
        $audit->log(action: 'review.extended', entityType: Tenant::class, entityId: (string) $model->getKey(), after: ['days' => $days]);

        return ApiResponse::success($this->shape($model->refresh()), 'Review access extended.');
    }

    public function revoke(AuditLogger $audit, string $tenant): JsonResponse
    {
        $model = $this->reviewTenant($tenant);
        $this->save($model, ['active' => false]);
        $audit->log(action: 'review.revoked', entityType: Tenant::class, entityId: (string) $model->getKey());

        return ApiResponse::success($this->shape($model->refresh()), 'Review access revoked.');
    }

    private function reviewTenant(string $id): Tenant
    {
        $model = Tenant::query()->findOrFail($id);
        abort_if(ReviewAccess::settingsOf($model) === null, 404, 'Not a review workspace.');

        return $model;
    }

    /** @param array<string,mixed> $changes */
    private function save(Tenant $model, array $changes): void
    {
        $settings = (array) ($model->settings ?? []);
        $settings['review'] = array_merge((array) ($settings['review'] ?? []), $changes);
        $model->forceFill(['settings' => $settings])->save();
    }

    /** @return array<string,mixed> */
    private function shape(Tenant $t): array
    {
        return ['id' => (string) $t->getKey(), 'name' => $t->name, 'slug' => $t->slug, 'live' => ReviewAccess::isLive($t)] + (ReviewAccess::settingsOf($t) ?? []);
    }
}
