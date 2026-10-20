<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Settings;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ATTRIB-CREDIT-001 — a project's chosen fractional attribution rules. See the migration.
 *
 * @property string $tenant_id
 * @property string $project_id
 * @property list<string> $models
 * @property int $lookback_days
 * @property int $half_life_days
 * @property array<string,float> $weights
 * @property string $version
 */
final class ProjectAttributionSetting extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'project_attribution_settings';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'project_id', 'models', 'lookback_days', 'half_life_days', 'weights', 'version', 'updated_by'];

    protected $casts = ['models' => 'array', 'weights' => 'array', 'lookback_days' => 'integer', 'half_life_days' => 'integer'];
}
