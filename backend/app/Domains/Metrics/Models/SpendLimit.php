<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Models;

use App\Domains\Metrics\Enums\SpendLimitScope;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An internal spend limit — the workspace's own, never the platform's (BUDGET-GOVERNANCE-001).
 *
 * Nothing in this product can stop an ad platform from spending. A row here is a number to measure
 * against and to warn about, and every payload built from it says so.
 *
 * The `date` casts hand back the framework's Carbon, not the base one.
 *
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 */
final class SpendLimit extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    /**
     * The one word every surface must carry beside a limit from this table.
     *
     * A constant rather than a literal per controller: the whole safety of this feature rests on the
     * reader knowing that CampaignsHub watches and does not enforce, and a sentence repeated by hand
     * in four places is a sentence that will exist in three of them.
     */
    public const ENFORCEMENT = 'internal_monitoring';

    protected $fillable = [
        'tenant_id', 'project_id', 'scope', 'scope_id', 'amount', 'currency',
        'starts_on', 'ends_on', 'thresholds', 'active', 'created_by',
    ];

    protected $casts = [
        'scope' => SpendLimitScope::class,
        'amount' => 'float',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'thresholds' => 'array',
        'active' => 'boolean',
    ];

    /** The thresholds somebody asked to hear about, ascending, with 100 always among them. */
    public function thresholdPercents(): array
    {
        $given = array_filter(
            array_map(static fn ($t): int => (int) $t, (array) ($this->thresholds ?? [])),
            static fn (int $t): bool => $t > 0 && $t <= 100,
        );

        // The limit itself is not optional. Somebody who configures «tell me at 50» still needs to
        // hear when it is reached, and a list that omitted 100 would be a limit nobody announces.
        $given[] = 100;

        $unique = array_values(array_unique($given));
        sort($unique);

        return $unique;
    }

    public function events(): HasMany
    {
        return $this->hasMany(SpendLimitEvent::class);
    }
}
