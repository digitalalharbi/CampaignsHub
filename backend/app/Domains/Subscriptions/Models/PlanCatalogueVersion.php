<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRICING-CATALOGUE-001 — one published edition of the plan catalogue. Plans belong to the edition they were sold under, so a
 * new edition never re-prices the customers of an old one; `retired` means no longer sold, not no longer honoured.
 *
 * @property int $number
 * @property string $label
 * @property string $currency
 * @property string $status draft|published|retired
 * @property bool $prices_include_vat
 */
final class PlanCatalogueVersion extends Model
{
    use HasUuids;

    protected $fillable = ['number', 'label', 'currency', 'status', 'published_at', 'published_by', 'notes', 'prices_include_vat'];

    protected $casts = ['number' => 'integer', 'published_at' => 'datetime', 'prices_include_vat' => 'boolean'];

    /** @return HasMany<SubscriptionPlan, $this> */
    public function plans(): HasMany
    {
        return $this->hasMany(SubscriptionPlan::class, 'catalogue_version_id');
    }
}
