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
final class StudioSession extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $table = 'studio_sessions';

    protected $guarded = [];

    protected $casts = ['source_item_ids' => 'array'];
}
