<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Context;

/**
 * Request-scoped holder for the "current tenant". Bound as a singleton so any layer can read the
 * active tenant without threading it through method signatures.
 *
 * SECURITY: the tenant id here is authoritative and must only ever be set from a trusted source
 * (the authenticated user / token / verified domain) — never from raw client input.
 */
final class TenantContext
{
    private ?string $tenantId = null;

    private bool $platformScope = false;

    public function setTenantId(?string $tenantId): void
    {
        $this->tenantId = $tenantId;
        $this->platformScope = false;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function forget(): void
    {
        $this->tenantId = null;
        $this->platformScope = false;
    }

    /**
     * Enter platform (cross-tenant) scope — used by super-admin/system flows that legitimately
     * operate across tenants. While active, the tenant global scope is not applied.
     */
    public function enterPlatformScope(): void
    {
        $this->platformScope = true;
    }

    public function isPlatformScope(): bool
    {
        return $this->platformScope;
    }
}
