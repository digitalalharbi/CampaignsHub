<?php

declare(strict_types=1);

namespace App\Domains\Platform\Support;

use App\Domains\Audit\AuditLogger;
use App\Domains\Platform\Models\SupportSession;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\Workspace;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * SUPERADMIN-SUPPORT-ACCESS-001 — «الدخول للدعم»: a platform admin sees ONE workspace as its members do, read-only, for a while.
 *
 * Not a bypass: it goes through the same door as everyone. `ResolveMembership` asks here first for a platform admin; with a live
 * support session it pins the request to that one tenant (`setTenantId`, which ends platform scope, so the admin sees that tenant's
 * rows and nobody else's) and gives it a membership that exists only for the request, never saved. Without one, nothing changes.
 *
 * What it refuses, centrally, before any controller runs:
 * - every write (anything but GET/HEAD/OPTIONS), except signing out;
 * - reads of secrets, money and exports: API tokens, invitations, webhooks, billing/subscriptions/payments, data requests, AI
 *   credentials, exports and downloads.
 *
 * Every visit is a row (who, which workspace, why, until when) and every start, end, refusal and page is audited against the TENANT,
 * so the workspace's own audit trail shows that support was inside it. Starting one needs the operator's own password again; it ends
 * on its own after 30 minutes.
 */
final class SupportAccess
{
    public const SESSION_KEY = 'support_session_id';

    public const MINUTES = 30;

    /** Reads refused even in a read-only visit: secrets, money, data that leaves the product. */
    private const DENIED_READS = [
        '#(^|/)(exports?|download)(/|$)#',
        '#^auth/tokens#',
        '#invitations#',
        '#webhook#',
        '#(^|/)(billing|subscriptions|payments)(/|$)#',
        '#^data-(requests|deletion)#',
        '#(^|/)ai/credentials#',
        '#(^|/)(shares|report-links)/[^/]+/(token|secret)#',
    ];

    /** The writes a visitor may still make: leaving. */
    private const ALLOWED_WRITES = ['#^auth/logout$#'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function start(User $admin, Tenant $tenant, string $reason, ?string $ip): SupportSession
    {
        $this->endFor($admin, 'replaced');

        $session = SupportSession::query()->create([
            'admin_user_id' => $admin->getKey(),
            'tenant_id' => $tenant->getKey(),
            'mode' => 'read',
            'reason' => $reason,
            'ip' => $ip,
            'started_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addMinutes(self::MINUTES),
        ]);

        $this->audit->log(
            action: 'support.session.started',
            entityType: Tenant::class,
            entityId: (string) $tenant->getKey(),
            after: ['support_session_id' => $session->id, 'mode' => 'read', 'expires_at' => $session->expires_at->toIso8601String()],
            reason: $reason,
            userId: (int) $admin->getKey(),
            tenantId: (string) $tenant->getKey(),
        );

        return $session;
    }

    /** The admin's live visit named by this browser's session, or null. An expired one is closed on sight. */
    public function liveFor(Request $request, User $admin): ?SupportSession
    {
        if (! $admin->is_platform_admin || ! $request->hasSession()) {
            return null;
        }

        $id = $request->session()->get(self::SESSION_KEY);
        if (! is_string($id) || $id === '') {
            return null;
        }

        $session = SupportSession::query()->whereKey($id)->where('admin_user_id', $admin->getKey())->first();

        if ($session === null || ! $session->isLive()) {
            if ($session !== null && $session->ended_at === null) {
                $this->close($session, 'expired', (int) $admin->getKey());
            }
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return $session;
    }

    public function endFor(User $admin, string $reason = 'ended'): void
    {
        SupportSession::query()->where('admin_user_id', $admin->getKey())->whereNull('ended_at')->get()
            ->each(fn (SupportSession $s) => $this->close($s, $reason, (int) $admin->getKey()));
    }

    /**
     * The membership this request runs as: never saved, marked as support, in the tenant's own portal and default workspace, so the
     * product's portal and scope checks apply to the visitor exactly as they do to a member.
     */
    public function membershipFor(SupportSession $session, User $admin): Membership
    {
        $tenant = Tenant::query()->findOrFail($session->tenant_id);

        $membership = new Membership;
        $membership->forceFill([
            'user_id' => $admin->getKey(),
            'tenant_id' => $tenant->getKey(),
            'portal' => $tenant->account_type === 'agency' ? Portal::Agency : Portal::App,
            'workspace_id' => Workspace::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->orderBy('created_at')->value('id'),
            'role' => 'support',
            'status' => 'active',
            'is_default' => true,
        ]);
        $membership->setAttribute('support_session_id', $session->id);
        $membership->exists = false;

        return $membership;
    }

    /** A refusal for anything a read-only visit may not do; null when the request may go on (and it is audited, once a minute). */
    public function deny(Request $request, User $admin, SupportSession $session): ?JsonResponse
    {
        $path = preg_replace('#^api/v1/#', '', ltrim($request->path(), '/')) ?? $request->path();
        $method = strtoupper($request->method());
        $isRead = in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);

        $refused = $isRead
            ? $this->matchesAny(self::DENIED_READS, $path)
            : ! $this->matchesAny(self::ALLOWED_WRITES, $path);

        if ($refused) {
            $this->record($request, $admin, $session, 'support.refused', $path);

            return response()->json([
                'success' => false,
                'message' => 'Support access is read-only and cannot reach secrets, payments or exports.',
                'code' => 'support_read_only',
            ], 403);
        }

        if (Cache::add('support-audit:'.$session->id.':'.$method.':'.$path, 1, 60)) {
            $this->record($request, $admin, $session, 'support.request', $path);
        }

        return null;
    }

    private function close(SupportSession $session, string $reason, int $adminId): void
    {
        $session->forceFill(['ended_at' => Carbon::now(), 'ended_reason' => $reason])->save();

        $this->audit->log(
            action: 'support.session.ended',
            entityType: Tenant::class,
            entityId: $session->tenant_id,
            after: ['support_session_id' => $session->id, 'ended_reason' => $reason],
            userId: $adminId,
            tenantId: $session->tenant_id,
        );
    }

    /** @param list<string> $patterns */
    private function matchesAny(array $patterns, string $path): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function record(Request $request, User $admin, SupportSession $session, string $action, string $path): void
    {
        $this->audit->log(
            action: $action,
            entityType: Tenant::class,
            entityId: $session->tenant_id,
            after: ['support_session_id' => $session->id, 'method' => $request->method(), 'path' => $path, 'ip' => $request->ip()],
            userId: (int) $admin->getKey(),
            tenantId: $session->tenant_id,
        );
    }
}
