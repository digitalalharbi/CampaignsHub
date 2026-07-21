<?php

declare(strict_types=1);

namespace App\Domains\Audit;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Http\Request;

/**
 * Central entry point for writing audit records. Enriches each entry with the current tenant,
 * actor, request metadata and correlation id.
 */
final class AuditLogger
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Request $request,
    ) {}

    /**
     * @param  array<string,mixed>|null  $before
     * @param  array<string,mixed>|null  $after
     */
    public function log(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?int $userId = null,
        ?string $tenantId = null,
    ): AuditLog {
        return AuditLog::create([
            'tenant_id' => $tenantId ?? ($this->context->hasTenant() ? $this->context->tenantId() : null),
            'user_id' => $userId ?? optional($this->request->user())->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $this->request->ip(),
            'user_agent' => (string) $this->request->userAgent(),
            'correlation_id' => $this->request->attributes->get('correlation_id'),
            'reason' => $reason,
        ]);
    }
}
