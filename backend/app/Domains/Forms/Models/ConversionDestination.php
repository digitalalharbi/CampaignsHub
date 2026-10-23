<?php

declare(strict_types=1);

namespace App\Domains\Forms\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FORMS-DISPATCH-001 — one place a project's lead conversions are reported. See the migration.
 *
 * `secrets` is encrypted and hidden: no serialisation of this model can carry it out.
 *
 * @property string $id
 * @property string $kind
 * @property string $status
 * @property array<string,mixed> $config
 * @property ?array<string,string> $secrets
 */
final class ConversionDestination extends Model
{
    use BelongsToTenant;
    use HasUuidKey;
    use SoftDeletes;

    public const TEST = 'test';

    public const LIVE = 'live';

    public const OFF = 'off';

    protected $fillable = ['tenant_id', 'project_id', 'lead_form_id', 'kind', 'name', 'status', 'config', 'secrets', 'created_by', 'went_live_at'];

    protected $hidden = ['secrets'];

    protected $casts = ['config' => 'array', 'secrets' => 'encrypted:array', 'went_live_at' => 'datetime'];
}
