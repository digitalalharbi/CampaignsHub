<?php

declare(strict_types=1);

namespace App\Domains\Taxonomy\Services;

use App\Domains\Taxonomy\Models\TaxonomyDefinition;
use App\Domains\Taxonomy\Models\TaxonomyOption;
use App\Domains\Tenancy\Scopes\TenantScope;
use Illuminate\Support\Collection;

/**
 * Read-only helper for the `request.paid_service` catalog. Deliberately narrow and FAIL-CLOSED: it ONLY ever
 * reads options where definition = request.paid_service AND scope = platform (tenant_id null) AND is_active
 * AND is_public — never any tenant/client/project scope, never inactive, never private, never another
 * definition. Safe to serve to the anonymous marketing homepage and to resolve display labels on
 * requests/quotes/invoices without leaking tenant data. `is_public` is a FILTER and is never exposed.
 */
final class PaidServiceCatalog
{
    public const DEFINITION = 'request.paid_service';

    /**
     * The public catalog payload (ApiEnvelope `data`) for the marketing homepage / intake selector. Contains
     * EXACTLY: version, categories[{key,label_ar,label_en,icon,sort_order}], and a FLAT services[] list with
     * {key,category_key,label_ar,label_en,description_ar,description_en,icon,sort_order,required_field_rules}.
     * Nothing else — no ids, no tenant_id, no is_public/is_system, no raw metadata, no pricing. Deterministic
     * ordering by (category sort_order, service sort_order).
     *
     * @return array{version:string, categories:list<array<string,mixed>>, services:list<array<string,mixed>>}
     */
    public function publicCatalog(): array
    {
        $options = $this->publicOptions();

        /** @var Collection<int, TaxonomyOption> $categoryOptions */
        $categoryOptions = $options->filter(fn (TaxonomyOption $o): bool => $o->parent_option_id === null)->values();

        // category id => category key, to stamp category_key on each flat service.
        $categoryKeyById = $categoryOptions->mapWithKeys(
            fn (TaxonomyOption $c): array => [(string) $c->getKey() => $c->key],
        );

        $categorySort = 0;
        $categories = $categoryOptions->map(function (TaxonomyOption $c) use (&$categorySort): array {
            $categorySort++;

            return [
                'key' => $c->key,
                'label_ar' => $c->label_ar,
                'label_en' => $c->label_en,
                'icon' => $c->icon,
                'sort_order' => $categorySort,
            ];
        })->all();

        $serviceSort = 0;
        $services = $options
            ->filter(fn (TaxonomyOption $o): bool => $o->parent_option_id !== null
                && $categoryKeyById->has((string) $o->parent_option_id))
            ->values()
            ->map(function (TaxonomyOption $s) use ($categoryKeyById, &$serviceSort): array {
                $serviceSort++;
                $metadata = is_array($s->metadata) ? $s->metadata : [];

                return [
                    'key' => $s->key,
                    'category_key' => $categoryKeyById[(string) $s->parent_option_id],
                    'label_ar' => $s->label_ar,
                    'label_en' => $s->label_en,
                    'description_ar' => $s->description,
                    'description_en' => (string) ($metadata['description_en'] ?? $s->label_en),
                    'icon' => $s->icon,
                    'sort_order' => $serviceSort,
                    'required_field_rules' => array_values((array) ($metadata['required_field_rules'] ?? [])),
                ];
            })->all();

        return [
            'version' => $this->version($options),
            'categories' => $categories,
            'services' => $services,
        ];
    }

    /**
     * The set of valid public SERVICE keys (child options only) mapped to their category_key — the whitelist a
     * request submit is validated against. A submitted key not in here is unknown/inactive/private → rejected.
     *
     * @return array<string, string> service_key => category_key
     */
    public function publicServiceMap(): array
    {
        $options = $this->publicOptions();

        $categoryKeyById = $options
            ->filter(fn (TaxonomyOption $o): bool => $o->parent_option_id === null)
            ->mapWithKeys(fn (TaxonomyOption $c): array => [(string) $c->getKey() => $c->key]);

        $map = [];
        foreach ($options as $option) {
            $parentId = $option->parent_option_id;
            if ($parentId !== null && $categoryKeyById->has((string) $parentId)) {
                $map[$option->key] = $categoryKeyById[(string) $parentId];
            }
        }

        return $map;
    }

    /**
     * Resolve a set of selected service keys into display rows for internal/portal surfacing (key + labels +
     * icon + color + category_key + required_field_rules). Reads the SAME fail-closed public set. Unknown keys
     * are returned with the key as a best-effort label (never dropped), so a surfaced request always shows every
     * key it stored. Order follows the input keys.
     *
     * @param  list<string>  $keys
     * @return list<array<string,mixed>>
     */
    public function resolve(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $options = $this->publicOptions();
        $byKey = $options->keyBy(fn (TaxonomyOption $o): string => $o->key);
        $categoryKeyById = $options
            ->filter(fn (TaxonomyOption $o): bool => $o->parent_option_id === null)
            ->mapWithKeys(fn (TaxonomyOption $c): array => [(string) $c->getKey() => $c->key]);

        $resolved = [];
        foreach ($keys as $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            /** @var TaxonomyOption|null $option */
            $option = $byKey->get($key);
            if ($option === null) {
                $resolved[] = ['key' => $key, 'category_key' => null, 'label_ar' => $key, 'label_en' => $key, 'icon' => null, 'color' => null, 'required_field_rules' => []];

                continue;
            }

            $metadata = is_array($option->metadata) ? $option->metadata : [];
            $parentId = $option->parent_option_id;
            $resolved[] = [
                'key' => $option->key,
                'category_key' => $parentId !== null ? ($categoryKeyById[(string) $parentId] ?? null) : null,
                'label_ar' => $option->label_ar,
                'label_en' => $option->label_en,
                'icon' => $option->icon,
                'color' => $option->color,
                'required_field_rules' => array_values((array) ($metadata['required_field_rules'] ?? [])),
            ];
        }

        return $resolved;
    }

    /**
     * A stable catalog version string that changes whenever the served options change (keys, labels, order,
     * activation). Used as the ETag basis. Derived deterministically from the option set — not stored — so it
     * needs no separate bump step and can never drift from the actual content.
     *
     * @param  Collection<int, TaxonomyOption>  $options
     */
    public function version(?Collection $options = null): string
    {
        $options ??= $this->publicOptions();

        $fingerprint = $options
            ->map(fn (TaxonomyOption $o): string => implode('|', [
                $o->key,
                (string) $o->parent_option_id,
                (string) $o->sort_order,
                $o->label_ar ?? '',
                $o->label_en ?? '',
                optional($o->updated_at)->timestamp ?? '',
            ]))
            ->implode("\n");

        return 'pms_'.substr(hash('sha256', $fingerprint), 0, 16);
    }

    /**
     * The FAIL-CLOSED served set: platform-scope (tenant_id null), active, PUBLIC options of the
     * request.paid_service definition, ordered deterministically. Bypasses the tenant global scope but pins
     * tenant_id = null + is_public = true, so no tenant/private/inactive option can ever be returned. Unknown
     * definition → empty (endpoint fails closed).
     *
     * @return Collection<int, TaxonomyOption>
     */
    private function publicOptions(): Collection
    {
        $definition = TaxonomyDefinition::withoutGlobalScope(TenantScope::class)
            ->where('key', self::DEFINITION)
            ->whereNull('tenant_id')
            ->first();

        if ($definition === null) {
            return new Collection;
        }

        return TaxonomyOption::withoutGlobalScope(TenantScope::class)
            ->where('taxonomy_definition_id', $definition->getKey())
            ->whereNull('tenant_id')
            ->where('is_active', true)
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get();
    }
}
