<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Capabilities;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * CAP-REGISTRY-001 — one on/off decision for one module at one level of one workspace.
 *
 * @property string $tenant_id
 * @property string $scope_type
 * @property string|null $scope_id
 * @property string $scope_key
 * @property string $module
 * @property bool $enabled
 */
final class CapabilitySwitch extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const TENANT = 'tenant';

    public const CLIENT = 'client';

    public const PROJECT = 'project';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'scope_type', 'scope_id', 'scope_key', 'module', 'enabled', 'changed_by'];

    protected $casts = ['enabled' => 'boolean'];
}
