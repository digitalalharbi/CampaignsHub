<?php

declare(strict_types=1);

namespace App\Domains\Taxonomy\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\Taxonomy\Exceptions\TaxonomyException;
use App\Domains\Taxonomy\Models\TaxonomyDefinition;
use App\Domains\Taxonomy\Models\TaxonomyOption;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Scopes\TenantScope;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The central Taxonomy & Option engine.
 *
 * Reads deliberately bypass the tenant global scope and re-apply an explicit "platform ∪ current tenant"
 * filter, so a tenant sees shared platform options plus its own — and NEVER another tenant's options. Every
 * mutation writes an Audit entry.
 *
 * System-sensitive definitions (request.status, request.payment_status, project.status, client.status,
 * campaign.objective, integration.category, …) are protected: their option keys and is_system flag are
 * immutable; only display label/translation/color/icon/active may change.
 *
 * Usage & reassignment are pluggable. This phase moves no records: merge/reassign return the affected option
 * state and audit the intent, while the adoption phase registers the real usage sources and reassignment
 * resolvers per definition key (see registerUsageSource / registerReassignmentResolver).
 */
final class TaxonomyService
{
    /**
     * Usage sources per definition key: each says "records referencing this option live in {table}.{column},
     * matched by the option key". Consulted by usage()/the delete guard via usageCountVia().
     *
     * @var array<string, list<array{table:string, column:string}>>
     */
    private static array $usageSources = [];

    /**
     * Reassignment resolvers per definition key. Given (fromOption, intoOption) the closure moves the bound
     * records and returns how many were moved. None registered → nothing moves (returns 0).
     *
     * @var array<string, Closure(TaxonomyOption, TaxonomyOption):int>
     */
    private static array $reassignmentResolvers = [];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    // ---------------------------------------------------------------------
    // Pluggable registration (the adoption phase supplies the real maps).
    // ---------------------------------------------------------------------

    public static function registerUsageSource(string $definitionKey, string $table, string $column): void
    {
        self::$usageSources[$definitionKey][] = ['table' => $table, 'column' => $column];
    }

    /**
     * @param  Closure(TaxonomyOption, TaxonomyOption):int  $resolver
     */
    public static function registerReassignmentResolver(string $definitionKey, Closure $resolver): void
    {
        self::$reassignmentResolvers[$definitionKey] = $resolver;
    }

    /** Clear all pluggable registrations (used by tests to isolate cases). */
    public static function flushRegistrations(): void
    {
        self::$usageSources = [];
        self::$reassignmentResolvers = [];
    }

    // ---------------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------------

    /**
     * Visible definitions (platform ∪ current tenant), optionally filtered by module. Ordered for a stable UI.
     *
     * @return Collection<int, TaxonomyDefinition>
     */
    public function definitions(?string $module = null): Collection
    {
        $query = TaxonomyDefinition::withoutGlobalScope(TenantScope::class)
            ->where(fn (Builder $q) => $this->applyVisibility($q));

        if ($module !== null) {
            $query->where('module', $module);
        }

        return $query->orderBy('module')->orderBy('sort_order')->orderBy('key')->get();
    }

    /**
     * The effective option set for a definition key: active, platform ∪ tenant, sorted, as a hierarchical tree
     * (root options each carry a `children` relation). Never returns another tenant's options.
     *
     * @return Collection<int, TaxonomyOption>
     */
    public function options(string $key, ?string $tenantId = null): Collection
    {
        $definition = $this->resolveDefinition($key);
        $tenantId = $this->assertTenantVisible($tenantId);

        /** @var Collection<int, TaxonomyOption> $all */
        $all = TaxonomyOption::withoutGlobalScope(TenantScope::class)
            ->where('taxonomy_definition_id', $definition->getKey())
            ->where('is_active', true)
            ->where(function (Builder $q) use ($tenantId): void {
                $q->whereNull('tenant_id');
                if ($tenantId !== null) {
                    $q->orWhere('tenant_id', $tenantId);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get();

        return $this->tree($all);
    }

    /** Resolve a visible option by id (fails closed on another tenant's option). */
    public function findOption(string $id): TaxonomyOption
    {
        return $this->resolveOption($id);
    }

    /** Count of live records referencing an option, summed across the registered usage sources. */
    public function usage(TaxonomyOption|string $option): int
    {
        $option = $this->resolveOption($option);
        $definitionKey = $this->definitionKeyOf($option);

        $count = 0;
        foreach (self::$usageSources[$definitionKey] ?? [] as $source) {
            $count += $this->usageCountVia($source['table'], $source['column'], $option->key);
        }

        return $count;
    }

    /** Low-level usage counter the adoption phase reuses: rows in {table} whose {column} equals {value}. */
    public function usageCountVia(string $table, string $column, string $value): int
    {
        return DB::table($table)->where($column, $value)->count();
    }

    // ---------------------------------------------------------------------
    // Mutations (each writes an Audit entry)
    // ---------------------------------------------------------------------

    /**
     * Create a tenant-scoped custom option under a definition that allows them. New options are never system.
     *
     * @param  array<string,mixed>  $data
     */
    public function createOption(string $key, array $data): TaxonomyOption
    {
        $definition = $this->resolveDefinition($key);

        if (! $definition->allows_custom_options) {
            throw new TaxonomyException("Definition [{$definition->key}] does not allow custom options.");
        }

        $tenantId = $this->context->tenantId();

        $option = new TaxonomyOption;
        $option->forceFill([
            'taxonomy_definition_id' => $definition->getKey(),
            'key' => (string) $data['key'],
            'label_ar' => (string) ($data['label_ar'] ?? $data['key']),
            'label_en' => (string) ($data['label_en'] ?? $data['key']),
            'description' => $data['description'] ?? null,
            'color' => $data['color'] ?? null,
            'icon' => $data['icon'] ?? null,
            'parent_option_id' => $data['parent_option_id'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? $this->nextSortOrder($definition, $tenantId)),
            'is_default' => false,
            'is_active' => true,
            'is_system' => false,
            'tenant_id' => $tenantId,
            'metadata' => $data['metadata'] ?? null,
        ]);
        $option->save();

        $this->audit->log('taxonomy.option.created', 'taxonomy_option', $option->getKey(), null, $this->snapshot($option));

        return $option;
    }

    /**
     * Update an option. A system option is locked down: only label_ar/label_en/description/color/icon/is_active
     * may change — key and is_system are always immutable.
     *
     * @param  array<string,mixed>  $data
     */
    public function updateOption(TaxonomyOption $option, array $data): TaxonomyOption
    {
        $option = $this->resolveOption($option);
        $before = $this->snapshot($option);

        $editable = $option->is_system
            ? ['label_ar', 'label_en', 'description', 'color', 'icon', 'is_active']
            : ['label_ar', 'label_en', 'description', 'color', 'icon', 'is_active', 'sort_order', 'is_default', 'metadata', 'parent_option_id'];

        foreach ($editable as $field) {
            if (array_key_exists($field, $data)) {
                $option->setAttribute($field, $data[$field]);
            }
        }

        // key and is_system are never assignable through this path (immutable), for system and custom alike.
        $option->save();

        $this->audit->log('taxonomy.option.updated', 'taxonomy_option', $option->getKey(), $before, $this->snapshot($option));

        return $option;
    }

    /**
     * Persist an explicit display order. Only options visible to the caller are touched.
     *
     * @param  list<string>  $ids
     */
    public function reorder(array $ids): void
    {
        $position = 0;
        foreach ($ids as $id) {
            $option = $this->resolveOption($id);
            $option->forceFill(['sort_order' => $position])->save();
            $position++;
        }

        $this->audit->log('taxonomy.option.reordered', 'taxonomy_option', null, null, ['order' => $ids]);
    }

    /** Make an option the single default for its definition within the same tenant scope. */
    public function setDefault(TaxonomyOption $option): TaxonomyOption
    {
        $option = $this->resolveOption($option);
        $before = $this->snapshot($option);

        DB::transaction(function () use ($option): void {
            TaxonomyOption::withoutGlobalScope(TenantScope::class)
                ->where('taxonomy_definition_id', $option->taxonomy_definition_id)
                ->where('tenant_id', $option->tenant_id)
                ->whereKeyNot($option->getKey())
                ->update(['is_default' => false]);

            $option->forceFill(['is_default' => true])->save();
        });

        $this->audit->log('taxonomy.option.default_set', 'taxonomy_option', $option->getKey(), $before, $this->snapshot($option));

        return $option;
    }

    /** Retire an option from selection without deleting it (safe alternative to delete). */
    public function deactivate(TaxonomyOption $option): TaxonomyOption
    {
        $option = $this->resolveOption($option);
        $before = $this->snapshot($option);

        $option->forceFill(['is_active' => false])->save();

        $this->audit->log('taxonomy.option.deactivated', 'taxonomy_option', $option->getKey(), $before, $this->snapshot($option));

        return $option;
    }

    /**
     * Add a child option under a parent (hierarchical fields).
     *
     * @param  array<string,mixed>  $data
     */
    public function createChild(TaxonomyOption $parent, array $data): TaxonomyOption
    {
        $parent = $this->resolveOption($parent);

        return $this->createOption($this->definitionKeyOf($parent), array_merge($data, [
            'parent_option_id' => $parent->getKey(),
        ]));
    }

    /**
     * Hard-delete an option. Blocked for system options and for any option still in use — the caller must
     * merge / reassign / deactivate instead. Guards the "no silent data loss" rule.
     */
    public function deleteOption(TaxonomyOption $option): void
    {
        $option = $this->resolveOption($option);

        if ($option->is_system) {
            throw new TaxonomyException('A system option cannot be deleted; deactivate it instead.');
        }

        $usage = $this->usage($option);
        if ($usage > 0) {
            throw new TaxonomyException(
                "Option [{$option->key}] is in use by {$usage} record(s); merge, reassign, or deactivate it instead.",
                409,
            );
        }

        $snapshot = $this->snapshot($option);
        $option->delete();

        $this->audit->log('taxonomy.option.deleted', 'taxonomy_option', $snapshot['id'], $snapshot, null);
    }

    /**
     * Merge `from` into `into`: reassign every record bound to `from` onto `into`, then soft-retire `from`.
     * Reassignment is delegated to the registered resolver (moves 0 until the adoption phase supplies one).
     *
     * @return array{from: array<string,mixed>, into: array<string,mixed>, reassigned: int}
     */
    public function merge(string $fromId, string $intoId): array
    {
        [$from, $into] = $this->resolveMergePair($fromId, $intoId);

        if ($from->is_system) {
            throw new TaxonomyException('A system option cannot be merged away; its key is immutable.');
        }

        $reassigned = $this->runReassignment($from, $into);
        $from->forceFill(['is_active' => false])->save();

        $result = [
            'from' => $this->snapshot($from),
            'into' => $this->snapshot($into),
            'reassigned' => $reassigned,
        ];

        $this->audit->log('taxonomy.option.merged', 'taxonomy_option', $from->getKey(), null, $result);

        return $result;
    }

    /**
     * Reassign records from `from` onto `into` without retiring `from` (keeps `from` selectable).
     *
     * @return array{from: array<string,mixed>, into: array<string,mixed>, reassigned: int}
     */
    public function reassign(string $fromId, string $intoId): array
    {
        [$from, $into] = $this->resolveMergePair($fromId, $intoId);

        $reassigned = $this->runReassignment($from, $into);

        $result = [
            'from' => $this->snapshot($from->refresh()),
            'into' => $this->snapshot($into->refresh()),
            'reassigned' => $reassigned,
        ];

        $this->audit->log('taxonomy.option.reassigned', 'taxonomy_option', $from->getKey(), null, $result);

        return $result;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /** Resolve a definition by key within the caller's visibility (tenant-private overrides platform). */
    private function resolveDefinition(string $key): TaxonomyDefinition
    {
        $tenantId = $this->context->tenantId();

        /** @var TaxonomyDefinition|null $definition */
        $definition = TaxonomyDefinition::withoutGlobalScope(TenantScope::class)
            ->where('key', $key)
            ->where(function (Builder $q) use ($tenantId): void {
                $q->whereNull('tenant_id');
                if ($tenantId !== null) {
                    $q->orWhere('tenant_id', $tenantId);
                }
            })
            ->orderByRaw('tenant_id IS NULL') // prefer a tenant-private definition over the platform one
            ->first();

        if ($definition === null) {
            throw new TaxonomyException("Unknown taxonomy definition [{$key}].", 404);
        }

        return $definition;
    }

    /** Resolve an option by id (or model) and assert the caller may see it (platform or own tenant). */
    private function resolveOption(TaxonomyOption|string $option): TaxonomyOption
    {
        if (! $option instanceof TaxonomyOption) {
            /** @var TaxonomyOption|null $found */
            $found = TaxonomyOption::withoutGlobalScope(TenantScope::class)->whereKey($option)->first();
            if ($found === null) {
                throw new TaxonomyException('Unknown taxonomy option.', 404);
            }
            $option = $found;
        }

        $this->assertOptionVisible($option);

        return $option;
    }

    /**
     * @return array{0: TaxonomyOption, 1: TaxonomyOption}
     */
    private function resolveMergePair(string $fromId, string $intoId): array
    {
        $from = $this->resolveOption($fromId);
        $into = $this->resolveOption($intoId);

        if ($from->getKey() === $into->getKey()) {
            throw new TaxonomyException('Cannot merge or reassign an option into itself.');
        }

        if ($from->taxonomy_definition_id !== $into->taxonomy_definition_id) {
            throw new TaxonomyException('Both options must belong to the same taxonomy definition.');
        }

        return [$from, $into];
    }

    /** Run the registered reassignment resolver for this definition, if any (moves 0 records otherwise). */
    private function runReassignment(TaxonomyOption $from, TaxonomyOption $into): int
    {
        $resolver = self::$reassignmentResolvers[$this->definitionKeyOf($from)] ?? null;

        return $resolver !== null ? $resolver($from, $into) : 0;
    }

    /** Fail closed on any option that is neither platform (null) nor owned by the current tenant. */
    private function assertOptionVisible(TaxonomyOption $option): void
    {
        $tenantId = $this->context->tenantId();

        if ($option->tenant_id !== null && $option->tenant_id !== $tenantId) {
            throw new TaxonomyException('This option belongs to another tenant.', 404);
        }
    }

    /** A caller may only ever ask for platform options or its own tenant's; anything else fails closed. */
    private function assertTenantVisible(?string $tenantId): ?string
    {
        $current = $this->context->tenantId();

        if ($tenantId === null) {
            return $current;
        }

        if ($current !== null && $tenantId !== $current) {
            throw new TaxonomyException('Cannot read another tenant\'s options.', 403);
        }

        return $tenantId;
    }

    /**
     * @param  Builder<TaxonomyDefinition>  $query
     * @return Builder<TaxonomyDefinition>
     */
    private function applyVisibility(Builder $query): Builder
    {
        $tenantId = $this->context->tenantId();

        $query->whereNull('tenant_id');
        if ($tenantId !== null) {
            $query->orWhere('tenant_id', $tenantId);
        }

        return $query;
    }

    /**
     * Assemble a flat, sorted option collection into a tree: root options (no parent) each carry a `children`
     * relation. Non-hierarchical definitions simply return every option as a root with no children.
     *
     * @param  Collection<int, TaxonomyOption>  $all
     * @return Collection<int, TaxonomyOption>
     */
    private function tree(Collection $all): Collection
    {
        $byParent = $all->groupBy(fn (TaxonomyOption $o) => $o->parent_option_id ?? '__root__');

        foreach ($all as $option) {
            /** @var Collection<int, TaxonomyOption> $children */
            $children = $byParent->get($option->getKey(), new Collection);
            $option->setRelation('children', $children->values());
        }

        /** @var Collection<int, TaxonomyOption> $roots */
        $roots = $byParent->get('__root__', new Collection);

        return $roots->values();
    }

    private function definitionKeyOf(TaxonomyOption $option): string
    {
        $definition = TaxonomyDefinition::withoutGlobalScope(TenantScope::class)
            ->whereKey($option->taxonomy_definition_id)
            ->first();

        return $definition === null ? '' : $definition->key;
    }

    private function nextSortOrder(TaxonomyDefinition $definition, ?string $tenantId): int
    {
        $max = TaxonomyOption::withoutGlobalScope(TenantScope::class)
            ->where('taxonomy_definition_id', $definition->getKey())
            ->where('tenant_id', $tenantId)
            ->max('sort_order');

        return $max === null ? 0 : ((int) $max) + 1;
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(TaxonomyOption $option): array
    {
        return [
            'id' => $option->getKey(),
            'key' => $option->key,
            'taxonomy_definition_id' => $option->taxonomy_definition_id,
            'label_ar' => $option->label_ar,
            'label_en' => $option->label_en,
            'is_default' => $option->is_default,
            'is_active' => $option->is_active,
            'is_system' => $option->is_system,
            'sort_order' => $option->sort_order,
            'tenant_id' => $option->tenant_id,
        ];
    }
}
