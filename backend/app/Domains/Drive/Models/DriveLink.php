<?php

declare(strict_types=1);

namespace App\Domains\Drive\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Google Drive folder linked at a scope (tenant|client|project|campaign). It stores the Drive folder id — a
 * reference, never file bytes. Unique per scope target so a scope points at exactly one folder. Tenant-scoped;
 * uuid PK.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $scope
 * @property string|null $scope_id
 * @property string $folder_id
 * @property string $folder_name
 * @property string|null $connection_id
 */
final class DriveLink extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'scope', 'scope_id', 'folder_id', 'folder_name', 'connection_id', 'created_by',
    ];

    /** @return HasMany<DriveFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(DriveFile::class);
    }
}
