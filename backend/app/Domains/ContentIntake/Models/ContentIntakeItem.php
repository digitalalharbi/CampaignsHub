<?php

declare(strict_types=1);

namespace App\Domains\ContentIntake\Models;

use App\Domains\ContentIntake\Services\IntakeTransitions;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A piece of NEW content on its way to launch. Tenant- and project-scoped. `status` is changed only by
 * {@see IntakeTransitions}.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $project_id
 * @property int|null $uploaded_by
 * @property string $name
 * @property string $kind
 * @property string|null $provider
 * @property string|null $objective
 * @property string|null $placement
 * @property string $status
 * @property string $source
 */
final class ContentIntakeItem extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $guarded = [];

    protected $hidden = ['disk', 'path'];

    protected $casts = [
        'checks' => 'array',
        'analysis' => 'array',
        'ai' => 'array',
        'size_bytes' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration_ms' => 'integer',
        'readiness_score' => 'integer',
        'drive_modified_time' => 'datetime',
        'source_changed_at' => 'datetime',
        'analyzed_at' => 'datetime',
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
        'launched_at' => 'datetime',
    ];

    /** @return HasMany<ContentIntakeEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ContentIntakeEvent::class, 'item_id')->orderBy('seq');
    }
}
