<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Models\Concerns;

use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apply to any operational model that carries a tenant_id. It:
 *   1. filters all queries by the current tenant (global TenantScope), and
 *   2. auto-fills tenant_id from the TenantContext on create.
 *
 * This is the backbone of tenant isolation — a developer cannot accidentally forget the where().
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            if ($model->getAttribute('tenant_id') === null) {
                $context = app(TenantContext::class);
                if ($context->hasTenant()) {
                    $model->setAttribute('tenant_id', $context->tenantId());
                }
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
