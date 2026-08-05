<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Models;

use App\Domains\Projects\Concerns\BelongsToProject;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A cart that never became an order.
 *
 * Only Salla reports these. Zid does not expose them at all, which is why its connector REFUSES rather
 * than returning an empty list — an absent row here must never be read as «nobody abandoned anything».
 */
final class CommerceAbandonedCart extends Model
{
    use BelongsToProject;
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'external_account_id', 'commerce_customer_id', 'provider',
        'external_id', 'abandoned_at', 'currency', 'total', 'items_count', 'customer_email',
        'checkout_url', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'click_id', 'click_id_provider', 'landing_url', 'referrer_url', 'is_demo', 'last_synced_at',
    ];

    protected $casts = [
        'abandoned_at' => 'datetime',
        'total' => 'decimal:6',
        'items_count' => 'integer',
        'is_demo' => 'boolean',
        'last_synced_at' => 'datetime',
    ];
}
