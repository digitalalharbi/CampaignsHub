<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Usage;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * PRICING-USAGE-CREDITS-001 — something that can be metered, who pays for each unit, and its actual cost.
 *
 * @property string $code
 * @property string $name
 * @property string $name_ar
 * @property string $unit
 * @property string $funded_by platform|customer
 * @property string|null $unit_cost
 * @property string $currency
 * @property bool $charges_credits
 * @property array<string, int>|null $included_per_plan
 * @property bool $is_active
 */
final class UsageMeter extends Model
{
    use HasUuids;

    public const PLATFORM = 'platform';

    public const CUSTOMER = 'customer';

    protected $fillable = ['code', 'name', 'name_ar', 'unit', 'funded_by', 'unit_cost', 'currency', 'charges_credits', 'included_per_plan', 'is_active', 'sort_order'];

    protected $casts = ['charges_credits' => 'boolean', 'included_per_plan' => 'array', 'is_active' => 'boolean', 'unit_cost' => 'decimal:6'];

    /**
     * Whether a unit of this meter may take credits. Only usage the PLATFORM pays for, at a cost the operator has entered, with
     * charging switched on. Usage on the customer's own key is theirs to pay their provider for — it never takes credits.
     */
    public function charges(): bool
    {
        return $this->funded_by === self::PLATFORM && $this->charges_credits && $this->unit_cost !== null && (float) $this->unit_cost > 0;
    }

    /** Units included each month on a plan, or null when the plan has no allowance for this meter. */
    public function includedFor(?string $planCode): ?int
    {
        if ($planCode === null) {
            return null;
        }
        $value = ($this->included_per_plan ?? [])[$planCode] ?? null;

        return $value === null ? null : (int) $value;
    }
}
