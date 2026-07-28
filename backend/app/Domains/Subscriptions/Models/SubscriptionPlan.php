<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A plan in the GLOBAL catalogue (starter|growth|scale). Not tenant-scoped — every tenant selects from the
 * same catalogue. `limits` holds the per-metric caps ({projects, team_members, connections,
 * reports_per_month, ...}); a null or absent value for a metric means unlimited.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property array<string,mixed>|null $features
 * @property array<string,int|null>|null $limits
 * @property bool $is_active
 */
final class SubscriptionPlan extends Model
{
    use HasUuidKey;

    protected $fillable = [
        'code', 'name', 'price_monthly', 'currency', 'features', 'limits', 'is_active',
    ];

    protected $casts = [
        'price_monthly' => 'decimal:2',
        'features' => 'array',
        'limits' => 'array',
        'is_active' => 'boolean',
    ];

    /** The cap for a metric, or null when the plan does not limit it (unlimited). */
    public function limitFor(string $metric): ?int
    {
        $value = $this->limits[$metric] ?? null;

        return $value === null ? null : (int) $value;
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }
}
