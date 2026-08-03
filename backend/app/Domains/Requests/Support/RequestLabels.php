<?php

declare(strict_types=1);

namespace App\Domains\Requests\Support;

use App\Domains\Taxonomy\Models\TaxonomyDefinition;
use App\Domains\Taxonomy\Models\TaxonomyOption;
use App\Domains\Tenancy\Scopes\TenantScope;
use Illuminate\Support\Facades\Cache;

/**
 * REQ-LABELS-001 — what a request's priority is CALLED, in the reader's language.
 *
 * Status labels come off `request_statuses`, which has carried `name_ar` and `name_en` all along.
 * Priority had neither: it is a plain string column on `external_requests`, and every surface rendered
 * the raw key. An Arabic inbox showed «medium» next to «تحت المراجعة», which is exactly the
 * «no technical terms for the user» rule being broken by the field most often glanced at.
 *
 * The labels live in the taxonomy engine — `request.priority` options already carry «حرجة / عالية /
 * متوسطة / منخفضة» — so this reads them rather than inventing a second list that would drift from the
 * one an operator can edit. Cached because it is called once per row of an inbox that routinely holds
 * hundreds, and a per-row query for a four-item lookup table is a page-load cost for nothing.
 *
 * An unknown key falls back to itself. That is deliberate: a priority somebody added in the taxonomy
 * but has not translated yet should appear as its key, which is ugly and visible and gets fixed —
 * rather than as an empty cell, which looks like missing data and gets ignored.
 */
final class RequestLabels
{
    private const CACHE_KEY = 'request-priority-labels';

    private const TTL = 3600;

    public static function priority(?string $key, string $locale = 'ar'): string
    {
        if ($key === null || $key === '') {
            return '';
        }

        $labels = self::all();

        return $labels[$key][$locale] ?? $key;
    }

    /** @return array<string, array{ar: string, en: string}> */
    public static function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL, function (): array {
            /*
             * Read outside the tenant scope, deliberately.
             *
             * Priorities are PLATFORM options (`tenant_id` null) shared by every tenant, and the tenant
             * scope filters those out whenever no tenant is set — which is the case in a console
             * command, a queued job, and anywhere the labels are wanted before a request is resolved.
             * Scoped, this returned an empty list and every priority silently fell back to its raw key,
             * which is the exact defect it was written to fix. `PaidServiceCatalog` reads the platform
             * catalogue the same way and for the same reason.
             */
            $definition = TaxonomyDefinition::withoutGlobalScope(TenantScope::class)
                ->where('key', 'request.priority')
                ->whereNull('tenant_id')
                ->first();

            if ($definition === null) {
                return [];
            }

            $options = TaxonomyOption::withoutGlobalScope(TenantScope::class)
                ->where('taxonomy_definition_id', $definition->getKey())
                ->whereNull('tenant_id')
                ->get(['key', 'label_ar', 'label_en']);

            $out = [];
            foreach ($options as $option) {
                $out[(string) $option->key] = [
                    'ar' => (string) ($option->label_ar ?: $option->key),
                    'en' => (string) ($option->label_en ?: $option->key),
                ];
            }

            return $out;
        });
    }

    /** Called when the taxonomy changes, so an edited label is not stale for an hour. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
