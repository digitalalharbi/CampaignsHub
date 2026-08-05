<?php

declare(strict_types=1);

namespace App\Domains\Settings\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Settings\Models\PublicPageSetting;
use App\Domains\Settings\Services\PublicPageDefaults;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/admin/settings` → Public site: the marketing homepage and the three public portals.
 *
 * ## Why this lives behind `platform` and not behind a tenant's settings
 *
 * PAGES-001. These were tenant-scoped, reachable at `/settings/public-pages` behind
 * `portal:app,agency,influencers`, while the console that renders them is `/admin` — where the
 * operator belongs to NO tenant. So the one screen that shows this editor could never load it: the
 * request was refused before it reached the controller, and the tab showed «تعذّر تحميل إعدادات
 * الصفحات» to the only person entitled to use it.
 *
 * The deeper problem the move fixes: there is one marketing homepage. When every tenant had a row and
 * the public endpoint read whichever was published last, a customer could rewrite the platform's own
 * front page — and the next customer to publish would take it from them. One document, one owner.
 *
 * ## Draft and published are separate on purpose
 *
 * The editor writes `draft` and previews it; only `publish` promotes it to `published`, which is the
 * ONLY thing a visitor reads. Every write is recorded in the audit log with its before/after payload.
 *
 * There is no `settings.manage` check on these actions: the `platform` middleware on the route group
 * is the gate, and a second permission test against a tenant role the operator does not hold is how
 * this screen locked out its own audience in the first place.
 */
final class PublicPageSettingsController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /** GET /admin/settings/public-pages — every editable page with its draft + published state. */
    public function index(): JsonResponse
    {
        $rows = PublicPageSetting::query()->platform()->get()->keyBy('page');

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

    /** PUT /admin/settings/public-pages/{page} — save the DRAFT only (never live). */
    public function update(Request $request, string $page): JsonResponse
    {
        abort_unless(in_array($page, PublicPageSetting::PAGES, true), 404);

        $data = $request->validate([
            'draft' => ['required', 'array'],
        ]);

        $row = $this->rowFor($page) ?? new PublicPageSetting(['page' => $page]);

        $before = $row->draft;
        $row->fill([
            'page' => $page,
            'draft' => $data['draft'],
            'updated_by' => $request->user()->id,
        ])->save();

        $this->audit->log('public_page.draft_saved', 'public_page_setting', (string) $row->getKey(), before: ['draft' => $before], after: ['draft' => $row->draft]);

        return ApiResponse::success($this->shape($row), 'Draft saved.');
    }

    /** POST /admin/settings/public-pages/{page}/publish — promote draft → published (what visitors see). */
    public function publish(Request $request, string $page): JsonResponse
    {
        abort_unless(in_array($page, PublicPageSetting::PAGES, true), 404);

        $row = $this->rowFor($page);
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

    /** POST /admin/settings/public-pages/{page}/revert — discard draft changes back to what is published. */
    public function revert(string $page): JsonResponse
    {
        abort_unless(in_array($page, PublicPageSetting::PAGES, true), 404);

        $row = $this->rowFor($page);
        abort_if($row === null, 404);

        $row->forceFill(['draft' => $row->published ?? PublicPageDefaults::for($page)])->save();

        $this->audit->log('public_page.reverted', 'public_page_setting', (string) $row->getKey());

        return ApiResponse::success($this->shape($row), 'Draft reverted to the published version.');
    }

    /**
     * GET /public/pages/{page} — PUBLIC (no auth): the published content only, so the marketing pages
     * and the public portals render edited copy without a code change. Falls back to shipped defaults.
     *
     * Reads the platform's own row by name, rather than «whichever row was published most recently
     * anywhere» — which is what previously let one tenant's publish decide what every visitor saw.
     */
    public function publicShow(string $page): JsonResponse
    {
        abort_unless(in_array($page, PublicPageSetting::PAGES, true), 404);

        $row = PublicPageSetting::query()->platform()
            ->where('page', $page)
            ->whereNotNull('published')
            ->first();

        return ApiResponse::success([
            'page' => $page,
            'content' => $row?->published ?? PublicPageDefaults::for($page),
            'source' => $row !== null ? 'published' : 'defaults',
            'version' => $row?->version ?? 0,
        ], 'Public page content.');
    }

    private function rowFor(string $page): ?PublicPageSetting
    {
        return PublicPageSetting::query()->platform()->where('page', $page)->first();
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
            'defaults' => PublicPageDefaults::for($row->page),
        ];
    }
}
