<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/** Maps an external entity (campaign, order, …) to an internal record. */
final class ExternalEntityMapping extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'external_account_id', 'entity_type', 'external_id',
        'internal_type', 'internal_id', 'raw',
    ];

    protected $casts = ['raw' => 'array'];
}
