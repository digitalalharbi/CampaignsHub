<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Models;

use App\Domains\Notifications\Services\NotificationInbox;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * One lifecycle row per (rule, entity) firing. last_triggered_at drives cooldown; status carries the
 * open → snoozed → resolved lifecycle. Tenant-scoped.
 */
final class AlertEvent extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'rule_id', 'type', 'entity_type', 'entity_id', 'dedup_key',
        'status', 'severity', 'context', 'notification_id', 'task_id',
        'last_triggered_at', 'snoozed_until', 'resolved_at',
        'priority', 'evidence', 'anatomy', 'decision_status', 'decision_reason', 'action_tag', 'owner_id', 'decided_at', 'impact_baseline',
    ];

    /**
     * NOTIF-BELL-001 — a notification closes with the alert that raised it.
     *
     * Hooked on the model rather than on one caller: an alert closes from the evaluator's own sweep (the condition
     * cleared), from a person pressing «حُلّ», and in bulk when a rule is removed — and the bell must follow all three.
     */
    protected static function booted(): void
    {
        static::updated(static function (self $event): void {
            if ($event->wasChanged('status') && $event->status === 'resolved') {
                app(NotificationInbox::class)->closeForAlert($event);
            }
        });
    }

    protected $casts = [
        'context' => 'array',
        'last_triggered_at' => 'datetime',
        'snoozed_until' => 'datetime',
        'resolved_at' => 'datetime',
        'evidence' => 'array',
        'anatomy' => 'array',
        'impact_baseline' => 'array',
        'decided_at' => 'datetime',
    ];
}
