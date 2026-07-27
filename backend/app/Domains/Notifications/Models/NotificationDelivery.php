<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-channel delivery ledger for a notification. Tracks the real state of each channel attempt:
 * queued | awaiting_credentials | sent | failed | retrying | suppressed_by_preference | suppressed_by_quiet_hours.
 * The in_app channel is "sent" the moment the AppNotification row exists; email stays awaiting_credentials
 * until a real mail provider is wired — we never record "sent" without one.
 */
final class NotificationDelivery extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $table = 'notification_deliveries';

    protected $fillable = [
        'tenant_id', 'notification_id', 'channel', 'status', 'attempts', 'dedup_key',
        'error', 'next_attempt_at', 'last_attempt_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'next_attempt_at' => 'datetime',
        'last_attempt_at' => 'datetime',
    ];
}
