<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * OPERATIONAL-INTELLIGENCE-P1-001 — one line of an alert's decision log: who did what, when, and the
 * state it replaced. APPEND-ONLY: an update or a delete through the model throws, so the history of a
 * decision cannot be rewritten by the code that wrote it.
 */
final class AlertEventDecision extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'alert_event_id', 'user_id', 'kind', 'from_status', 'to_status',
        'from_owner_id', 'to_owner_id', 'action_tag', 'note', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        self::updating(static fn () => throw new LogicException('The decision log is append-only.'));
        self::deleting(static fn () => throw new LogicException('The decision log is append-only.'));
    }
}
