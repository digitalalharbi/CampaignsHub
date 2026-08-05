<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Review;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * REVIEW-001 — the per-provider platform-review checklist, in the operator's console.
 *
 * Behind the `platform` gate with the rest of `/admin`: an OAuth app belongs to the platform
 * operator, and its review status is one fact for every customer rather than a per-tenant one.
 */
final class ReviewChecklistController extends Controller
{
    public function __construct(private readonly ReviewChecklistService $checklists) {}

    /** Every provider's checklist, for the board. */
    public function index(): JsonResponse
    {
        $providers = array_map(
            fn ($definition) => $this->checklists->for($definition->key),
            ProviderCatalogue::all(),
        );

        return ApiResponse::success(['providers' => array_values($providers)], 'Review checklists.');
    }

    public function show(string $provider): JsonResponse
    {
        abort_unless(ProviderCatalogue::has($provider), 404);

        return ApiResponse::success($this->checklists->for($provider), 'Review checklist.');
    }

    /**
     * Record what the operator has done inside the provider's own console.
     *
     * A DERIVED requirement is refused rather than silently ignored: it is answered from the system
     * on every read, and letting somebody tick it would produce a checklist that disagrees with
     * itself the next time the page loads.
     */
    public function update(Request $request, string $provider, string $requirement): JsonResponse
    {
        abort_unless(ProviderCatalogue::has($provider), 404);

        $definition = ProviderCatalogue::get($provider);
        $known = collect(ReviewCatalogue::for($definition->key))->firstWhere('key', $requirement);

        abort_if($known === null, 404);
        abort_if(
            $known['source'] === ReviewCatalogue::SOURCE_DERIVED,
            422,
            'This requirement is determined by the system and cannot be set by hand.',
        );

        $data = $request->validate([
            'status' => ['required', Rule::in(ProviderReviewItem::STATUSES)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        ProviderReviewItem::updateOrCreate(
            ['provider' => $definition->key, 'requirement' => $requirement],
            [...$data, 'updated_by' => $request->user()?->id],
        );

        AuditLog::create([
            'tenant_id' => null,
            'user_id' => $request->user()?->id,
            'action' => 'integrations.review.updated',
            'entity_type' => ProviderReviewItem::class,
            'entity_id' => $definition->key.':'.$requirement,
            'after' => ['status' => $data['status']],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return ApiResponse::success($this->checklists->for($definition->key), 'Requirement updated.');
    }
}
