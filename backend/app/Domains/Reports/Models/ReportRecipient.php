<?php

declare(strict_types=1);

namespace App\Domains\Reports\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

final class ReportRecipient extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'report_id', 'schedule_id', 'email', 'name', 'last_sent_at', 'is_demo',
    ];

    protected $casts = [
        'last_sent_at' => 'datetime',
        'is_demo' => 'boolean',
    ];
}
