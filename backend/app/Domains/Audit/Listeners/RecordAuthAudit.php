<?php

declare(strict_types=1);

namespace App\Domains\Audit\Listeners;

use App\Domains\Audit\AuditLogger;
use App\Domains\Tenancy\Context\MembershipContext;
use App\Domains\Tenancy\Models\Membership;
use App\Models\User;
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
        // Stamp last-login for the team roster (best-effort; never blocks auth).
        if (method_exists($event->user, 'forceFill')) {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
        }

        $this->audit->log(
            action: 'user.login',
            entityType: $event->user::class,
            entityId: (string) $event->user->getAuthIdentifier(),
            userId: (int) $event->user->getAuthIdentifier(),
            tenantId: self::auditTenantId($event->user),
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
            tenantId: self::auditTenantId($event->user),
        );
    }

    /**
     * Which workspace to stamp on a sign-in or sign-out record.
     *
     * The active membership if one is established (sign-out, mid-session), otherwise the user's
     * default — read from the membership layer rather than from `users.tenant_id`, so an audit trail
     * cannot name a workspace the person no longer belongs to.
     */
    private static function auditTenantId(mixed $user): ?string
    {
        $active = app(MembershipContext::class)->tenantId();
        if ($active !== null) {
            return $active;
        }

        if (! $user instanceof User) {
            return null;
        }

        return Membership::query()->forUser($user->id)->active()
            ->orderByDesc('is_default')->value('tenant_id');
    }
}
