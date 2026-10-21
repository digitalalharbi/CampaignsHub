<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Launch;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * LAUNCH-001 — one multi-platform launch: what was asked (`spec`) and what each provider answered (`steps`).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $project_id
 * @property string|null $client_workspace_id
 * @property string|null $unified_campaign_id
 * @property string $name
 * @property string $objective
 * @property string $status
 * @property string $idempotency_key
 * @property array<string, mixed> $spec
 * @property list<array<string, mixed>> $steps
 * @property bool $launch
 * @property int|null $created_by
 * @property int|null $published_by
 * @property Carbon|null $published_at
 */
final class LaunchPlan extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    public const DRAFT = 'draft';

    public const PUBLISHING = 'publishing';

    public const PUBLISHED = 'published';

    public const PARTIAL = 'partial';

    public const FAILED = 'failed';

    protected $fillable = [
        'tenant_id', 'project_id', 'client_workspace_id', 'unified_campaign_id', 'name', 'objective', 'status',
        'idempotency_key', 'spec', 'steps', 'launch', 'created_by', 'published_by', 'published_at',
    ];

    protected $casts = [
        'spec' => 'array',
        'steps' => 'array',
        'launch' => 'boolean',
        'published_at' => 'datetime',
    ];
}
