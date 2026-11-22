<?php

declare(strict_types=1);

namespace App\Domains\ContentStudio\Models;

use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * CONTENT-STUDIO-001 batch 8 — a layout family. `tenant_id` null = a system family every workspace reads; a tenant's own
 * family is read only by that tenant ({@see self::scopeVisible()}).
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $key
 * @property int $version
 * @property array<string, mixed> $definition
 * @property bool $is_system
 */
final class LayoutFamily extends Model
{
    use HasUuidKey;

    protected $guarded = [];

    protected $casts = ['definition' => 'array', 'is_system' => 'boolean', 'version' => 'integer'];

    /** @param Builder<self> $query @return Builder<self> */
    public function scopeVisible(Builder $query): Builder
    {
        $tenant = app(TenantContext::class)->hasTenant() ? app(TenantContext::class)->tenantId() : null;

        return $query->where(fn (Builder $q) => $q->whereNull('tenant_id')->when($tenant !== null, fn (Builder $q) => $q->orWhere('tenant_id', $tenant)));
    }
}
