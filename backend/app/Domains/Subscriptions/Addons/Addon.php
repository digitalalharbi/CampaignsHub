<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Addons;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * PRICING-ADDONS-001 — one add-on in the catalogue: capacity (extra seats) or a feature pack.
 *
 * @property string $code
 * @property string $kind capacity|feature
 * @property string|null $price_monthly
 * @property string $currency
 * @property array<string, mixed>|null $grants
 * @property string $readiness available|coming_soon
 * @property bool $is_active
 */
final class Addon extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'kind', 'name', 'name_ar', 'summary_en', 'summary_ar', 'price_monthly', 'currency', 'grants', 'readiness', 'is_active', 'sort_order'];

    protected $casts = ['grants' => 'array', 'is_active' => 'boolean', 'price_monthly' => 'decimal:2'];

    /** Sold now: ready, active and priced. Anything else may be shown but never requested or charged. */
    public function isSold(): bool
    {
        return $this->is_active && $this->readiness === 'available' && $this->price_monthly !== null;
    }
}
