<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * INTEG-RAW-001 — one platform response, exactly as it arrived.
 *
 * Tenant-scoped like everything else, because a payload is customer data: it carries their campaign
 * names, their spend and their account ids. A raw table that skipped the tenant scope would be the
 * single easiest place in this system to read another customer's numbers.
 */
final class IntegrationRawPayload extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'external_account_id', 'sync_run_id', 'provider', 'resource',
        'window_start', 'window_end', 'payload', 'normalised_rows', 'fetched_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'window_start' => 'date',
        'window_end' => 'date',
        'fetched_at' => 'datetime',
        'normalised_rows' => 'integer',
    ];
}
