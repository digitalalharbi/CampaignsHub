<?php

declare(strict_types=1);

namespace App\Domains\Taxonomy\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An allowed value under a definition. Platform (tenant_id null) options are shared with all tenants; a tenant
 * may add its own private options when the definition allows_custom_options. parent_option_id supports
 * hierarchical fields. A used option can never be hard-deleted — merge / reassign / deactivate instead.
 *
 * @property string $id
 * @property string $key
 * @property string $taxonomy_definition_id
 * @property string|null $parent_option_id
 * @property int $sort_order
 * @property bool $is_default
 * @property bool $is_active
 * @property bool $is_system
 * @property string|null $tenant_id
 * @property array<string,mixed>|null $metadata
 */
final class TaxonomyOption extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'key', 'taxonomy_definition_id', 'label_ar', 'label_en', 'description', 'color', 'icon',
        'parent_option_id', 'sort_order', 'is_default', 'is_active', 'is_system', 'tenant_id', 'metadata',
    ];

    protected $casts = [
        'sort_order' => 'int',
        'is_default' => 'bool',
        'is_active' => 'bool',
        'is_system' => 'bool',
        'metadata' => 'array',
    ];

    /** @return BelongsTo<TaxonomyDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(TaxonomyDefinition::class, 'taxonomy_definition_id');
    }

    /** @return BelongsTo<TaxonomyOption, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_option_id');
    }

    /** @return HasMany<TaxonomyOption, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_option_id');
    }

    /**
     * How many live records currently reference this option. Zero for now — real usage wiring is delivered in
     * the adoption phase via TaxonomyService usage sources (see usageCountVia). Kept on the model so callers can
     * ask an option directly without reaching for the service.
     */
    public function usageCount(): int
    {
        return 0;
    }
}
