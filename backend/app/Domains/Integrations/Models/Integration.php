<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

final class Integration extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'connector_key', 'status', 'ad_account_id', 'meta', 'last_synced_at', 'last_sync_error',
    ];

    protected $casts = [
        'meta' => 'array',
        'last_synced_at' => 'datetime',
    ];

    // The encrypted credentials blob is never exposed via the API.
    protected $hidden = ['credentials'];
}
