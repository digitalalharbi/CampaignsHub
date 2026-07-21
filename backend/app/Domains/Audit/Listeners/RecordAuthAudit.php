<?php

declare(strict_types=1);

namespace App\Domains\Audit\Listeners;

use App\Domains\Audit\AuditLogger;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * Records authentication lifecycle events (login/logout) into the audit trail.
 */
final class RecordAuthAudit
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handleLogin(Login $event): void
    {
        $this->audit->log(
            action: 'user.login',
            entityType: $event->user::class,
            entityId: (string) $event->user->getAuthIdentifier(),
            userId: (int) $event->user->getAuthIdentifier(),
            tenantId: $event->user->tenant_id ?? null,
        );
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user === null) {
            return;
        }

        $this->audit->log(
            action: 'user.logout',
            entityType: $event->user::class,
            entityId: (string) $event->user->getAuthIdentifier(),
            userId: (int) $event->user->getAuthIdentifier(),
            tenantId: $event->user->tenant_id ?? null,
        );
    }
}
