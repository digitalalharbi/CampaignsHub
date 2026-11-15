<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * An external subscription (spec §9, §10). Tenant-scoped; its projects are read with the tenant in the WHERE.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $recipient_id
 * @property string $status
 */
final class NotificationSubscription extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $table = 'notification_subscriptions';

    protected $fillable = [
        'tenant_id', 'recipient_id', 'status', 'template_key', 'digest_types', 'event_keys', 'period_key', 'frequency', 'weekdays',
        'month_day', 'send_time', 'timezone', 'subject_template', 'cc', 'bcc', 'review_mode', 'reviews_remaining', 'overrides',
        'next_run_at', 'last_run_at', 'legacy_source', 'legacy_id', 'created_by',
    ];

    protected $casts = [
        'digest_types' => 'array', 'event_keys' => 'array', 'weekdays' => 'array', 'cc' => 'array', 'bcc' => 'array',
        'overrides' => 'array', 'review_mode' => 'boolean', 'next_run_at' => 'datetime', 'last_run_at' => 'datetime',
    ];

    /** @return list<string> */
    public function projectIds(): array
    {
        return DB::table('notification_subscription_projects')->where('subscription_id', $this->id)->where('tenant_id', $this->tenant_id)
            ->orderBy('project_id')->pluck('project_id')->map(static fn ($id): string => (string) $id)->all();
    }

    /** @param list<string> $projectIds already checked to belong to this tenant */
    public function bindProjects(array $projectIds): void
    {
        DB::table('notification_subscription_projects')->where('subscription_id', $this->id)->delete();
        DB::table('notification_subscription_projects')->insert(array_map(fn (string $p): array => [
            'subscription_id' => $this->id, 'project_id' => $p, 'tenant_id' => $this->tenant_id,
        ], array_values(array_unique($projectIds))));
    }
}
