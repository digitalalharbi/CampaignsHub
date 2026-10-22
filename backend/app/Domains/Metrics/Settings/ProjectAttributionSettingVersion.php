<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Settings;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** ATTRIB-PRESETS-001 — one row per change, so a comparison can name the configuration it was computed under. */
final class ProjectAttributionSettingVersion extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'project_attribution_setting_versions';

    protected $fillable = ['tenant_id', 'project_id', 'number', 'preset', 'models', 'lookback_days', 'half_life_days', 'weights', 'advanced', 'version', 'changed_by', 'created_at'];

    protected $casts = ['models' => 'array', 'weights' => 'array', 'advanced' => 'array', 'number' => 'integer', 'lookback_days' => 'integer', 'half_life_days' => 'integer', 'created_at' => 'datetime'];
}
