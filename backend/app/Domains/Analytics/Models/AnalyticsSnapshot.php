<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ANALYTICS-DATA-001 — a heavy analytics read, computed off the request path and stored for one tenant, one
 * project and one window. A reader gets the row only when it is fresh; it is never a source of truth.
 *
 * @property string $tenant_id
 * @property string $project_id
 * @property string $section
 * @property array<string, mixed> $payload
 * @property Carbon $computed_at
 */
final class AnalyticsSnapshot extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $table = 'analytics_snapshots';

    protected $fillable = ['tenant_id', 'project_id', 'section', 'period_from', 'period_to', 'payload', 'computed_at'];

    protected $casts = ['payload' => 'array', 'computed_at' => 'datetime'];
}
