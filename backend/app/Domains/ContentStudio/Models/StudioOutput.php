<?php

declare(strict_types=1);

namespace App\Domains\ContentStudio\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * CONTENT-STUDIO-001 batch 8 — tenant-scoped (global scope) and project-scoped (checked by the controller).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $project_id
 */
final class StudioOutput extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $table = 'studio_outputs';

    protected $guarded = [];

    protected $casts = ['params' => 'array', 'ai_generated' => 'boolean', 'has_audio' => 'boolean', 'rating' => 'integer', 'duration_ms' => 'integer', 'family_version' => 'integer'];
}
