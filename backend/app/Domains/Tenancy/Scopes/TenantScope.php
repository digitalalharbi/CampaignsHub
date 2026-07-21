<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Scopes;

use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope that constrains every query on a tenant-owned model to the current tenant.
 * Bypassed only when the request is explicitly in platform (cross-tenant) scope.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isPlatformScope()) {
            return;
        }

        // With no resolved tenant we must fail closed: return nothing rather than everything.
        if (! $context->hasTenant()) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->getTable().'.tenant_id', $context->tenantId());
    }
}
