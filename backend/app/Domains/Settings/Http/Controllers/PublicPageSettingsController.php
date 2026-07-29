<?php

declare(strict_types=1);

namespace App\Domains\Settings\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Settings\Models\PublicPageSetting;
use App\Domains\Settings\Services\PublicPageDefaults;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * System Settings → editable public surfaces (marketing homepage + the three external portals).
 *
 * Draft/publish split: the editor writes `draft` and previews it; only `publish` promotes it to `published`,
 * which is the ONLY thing public visitors read. Every write is permission-gated (settings.manage), tenant
 * scoped, and recorded in the audit log with the before/after payload.
 */
final class PublicPageSettingsController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /settings/public-pages — every editable page with its draft + published state. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.manage'), 403);

        $rows = PublicPageSetting::query()->get()->keyBy('page');

        $pages = collect(PublicPageSetting::PAGES)->map(function (string $page) use ($rows): array {
            $row = $rows->get($page);
            $defaults = PublicPageDefaults::for($page);

            return [
                'page' => $page,
                'draft' => $row?->draft ?? $defaults,
                'published' => $row?->published,
                'is_published' => $row?->published !== null,
                'has_unpublished_changes' => $row !== null && $row->draft !== $row->published,
                'version' => $row?->version ?? 0,
                'published_at' => optional($row?->published_at)->toIso8601String(),
                'defaults' => $defaults,
            ];
        })->values();

        return ApiResponse::success($pages->all(), 'Public page settings.');
    }

    /** PUT /settings/public-pages/{page} — save the DRAFT only (never live). */
    public function update(Request $request, string $page): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.manage'), 403);
        abort_unless(in_array($page, PublicPageSetting::PAGES, true), 404);

        $data = $request->validate([
            'draft' => ['required', 'array'],
        ]);

        $row = PublicPageSetting::firstOrNew(['page' => $page]);
        $before = $row->draft;
        $row->fill([
            'tenant_id' => $this->tenant->tenantId(),
            'page' => $page,
            'draft' => $data['draft'],
            'updated_by' => $request->user()->id,
        ])->save();

        $this->audit->log('public_page.draft_saved', 'public_page_setting', (string) $row->getKey(), before: ['draft' => $before], after: ['draft' => $row->draft]);

        return ApiResponse::success($this->shape($row), 'Draft saved.');
    }

    /** POST /settings/public-pages/{page}/publish — promote draft → published (what visitors see). */
    public function publish(Request $request, string $page): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.manage'), 403);
        abort_unless(in_array($page, PublicPageSetting::PAGES, true), 404);

        $row = PublicPageSetting::where('page', $page)->first();
        abort_if($row === null || $row->draft === null, 422, 'Nothing to publish — save a draft first.');

        $before = $row->published;
        $row->forceFill([
            'published' => $row->draft,
            'version' => $row->version + 1,
            'published_by' => $request->user()->id,
            'published_at' => now(),
        ])->save();

        $this->audit->log('public_page.published', 'public_page_setting', (string) $row->getKey(), before: ['published' => $before], after: ['published' => $row->published, 'version' => $row->version]);

        return ApiResponse::success($this->shape($row), 'Published.');
    }

    /** POST /settings/public-pages/{page}/revert — discard draft changes back to what is published. */
    public function revert(Request $request, string $page): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.manage'), 403);
        abort_unless(in_array($page, PublicPageSetting::PAGES, true), 404);

        $row = PublicPageSetting::where('page', $page)->firstOrFail();
        $row->forceFill(['draft' => $row->published ?? PublicPageDefaults::for($page)])->save();

        $this->audit->log('public_page.reverted', 'public_page_setting', (string) $row->getKey());

        return ApiResponse::success($this->shape($row), 'Draft reverted to the published version.');
    }

    /**
     * GET /public/pages/{page} — PUBLIC (no auth): the published content only, so marketing pages and the
     * external portals render tenant-edited copy without a code change. Falls back to shipped defaults.
     */
    public function publicShow(Request $request, string $page): JsonResponse
    {
        abort_unless(in_array($page, PublicPageSetting::PAGES, true), 404);

        $validated = $request->validate([
            'tenant' => ['nullable', 'string', 'max:64'],
        ]);

        $row = PublicPageSetting::withoutGlobalScopes()
            ->where('page', $page)
            ->when(
                isset($validated['tenant']),
                fn ($q) => $q->whereIn('tenant_id', fn ($sub) => $sub->select('id')->from('tenants')->where('slug', $validated['tenant'])),
            )
            ->whereNotNull('published')
            ->latest('published_at')
            ->first();

        return ApiResponse::success([
            'page' => $page,
            'content' => $row?->published ?? PublicPageDefaults::for($page),
            'source' => $row !== null ? 'published' : 'defaults',
            'version' => $row?->version ?? 0,
        ], 'Public page content.');
    }

    /** @return array<string,mixed> */
    private function shape(PublicPageSetting $row): array
    {
        return [
            'page' => $row->page,
            'draft' => $row->draft,
            'published' => $row->published,
            'is_published' => $row->published !== null,
            'has_unpublished_changes' => $row->draft !== $row->published,
            'version' => $row->version,
            'published_at' => optional($row->published_at)->toIso8601String(),
        ];
    }
}
