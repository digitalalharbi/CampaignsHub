<?php

declare(strict_types=1);

namespace App\Domains\Taxonomy\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A classification field (e.g. request.status, client.industry). tenant_id null = platform (shared with all
 * tenants); a non-null tenant_id is a tenant-private field. is_system fields keep immutable option keys — only
 * display label/translation/color/active may change on their options.
 *
 * @property string $id
 * @property string $key
 * @property string $module
 * @property string $scope
 * @property string $field_type
 * @property bool $is_system
 * @property bool $is_active
 * @property bool $allows_custom_options
 * @property bool $allows_multiple
 * @property int|null $maximum_selections
 * @property int $sort_order
 * @property string|null $tenant_id
 */
final class TaxonomyDefinition extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'key', 'module', 'scope', 'field_type', 'label_ar', 'label_en', 'description',
        'is_system', 'is_active', 'allows_custom_options', 'allows_multiple',
        'maximum_selections', 'sort_order', 'tenant_id',
    ];

    protected $casts = [
        'is_system' => 'bool',
        'is_active' => 'bool',
        'allows_custom_options' => 'bool',
        'allows_multiple' => 'bool',
        'maximum_selections' => 'int',
        'sort_order' => 'int',
    ];

    /** @return HasMany<TaxonomyOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(TaxonomyOption::class);
    }
}
