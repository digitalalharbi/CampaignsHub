<?php

declare(strict_types=1);

namespace App\Domains\Widgets\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * WIDGET-REGISTRY-001 — what someone chose for one surface of one project or one shared link. See the migration.
 *
 * @property string $tenant_id
 * @property string $scope_type
 * @property string $scope_id
 * @property string $surface
 * @property list<array{key: string, visible: bool, order: int, size: string}> $items
 */
final class WidgetLayout extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const PROJECT = 'project';

    public const SHARE_LINK = 'share_link';

    protected $table = 'widget_layouts';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'scope_type', 'scope_id', 'surface', 'items', 'updated_by'];

    protected $casts = ['items' => 'array'];
}
