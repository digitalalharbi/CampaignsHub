<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Billing\Models\Invoice;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The platform owner's overview (ADMIN-001).
 *
 * Counts ACROSS tenants, which is the one thing no other surface in the product is allowed to do —
 * so it enters platform scope explicitly rather than relying on the caller happening to have none.
 * A count that silently returned one tenant's figures would be worse than no console: the owner
 * would read "3 tenants" and believe it.
 *
 * Everything here is a count or a status. No tenant's campaign data, client names or figures appear:
 * owning the platform is not a reason to read a customer's numbers, and doing so from a dashboard
 * would leave no audit trail that it happened.
 */
final class PlatformOverviewController extends Controller
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** GET /api/v1/admin/overview */
    public function __invoke(): JsonResponse
    {
        // Explicit: these queries MUST cross tenants, and saying so is safer than assuming the
        // request arrived unscoped.
        $this->tenants->enterPlatformScope();

        $tenants = Tenant::query()->get(['id', 'status', 'account_type', 'subscription_plan', 'created_at']);

        return ApiResponse::success([
            'tenants' => [
                'total' => $tenants->count(),
                'active' => $tenants->where('status', 'active')->count(),
                'suspended' => $tenants->where('status', 'suspended')->count(),
                'by_account_type' => $tenants->groupBy(fn (Tenant $t) => $t->account_type ?? 'unset')
                    ->map->count()->all(),
                'by_plan' => $tenants->groupBy(fn (Tenant $t) => $t->subscription_plan ?? 'none')
                    ->map->count()->all(),
            ],
            'people' => [
                'users' => User::query()->count(),
                'platform_admins' => User::query()->where('is_platform_admin', true)->count(),
                'memberships' => Membership::query()->count(),
                // Users who belong to no workspace at all. Not an error on its own — a platform
                // admin is one — but a growing number means a grant path is dropping people.
                'without_membership' => User::query()->where('is_platform_admin', false)
                    ->whereDoesntHave('memberships')->count(),
            ],
            'workload' => [
                'client_workspaces' => ClientWorkspace::query()->count(),
                'open_requests' => ExternalRequest::query()
                    ->whereHas('status', fn ($q) => $q->where('is_terminal', false))->count(),
                'unpaid_invoices' => Invoice::query()->whereNotIn('status', ['paid', 'cancelled'])->count(),
            ],
            'growth' => $this->growth(),
            'subscriptions' => $this->subscriptions(),
            'attention' => $this->attention(),
            'operations' => [
                'connections' => $this->connectionsNeedingReconnect(),
                'alerts' => $this->openAlerts(),
                'recent' => $this->recentConsoleActivity(),
            ],
        ], 'Platform overview.');
    }

    /**
     * Tenants opened per month, twelve months back, cumulative alongside.
     *
     * Every month in the window is present even when it is zero. A series that omits empty months
     * draws a line through the gap and turns a quiet quarter into apparent steady growth — the one
     * reading a growth chart must never invite.
     *
     * @return list<array{month: string, opened: int, total: int}>
     */
    private function growth(): array
    {
        $counts = Tenant::query()
            ->selectRaw("to_char(created_at, 'YYYY-MM') as month, count(*) as opened")
            ->groupBy('month')
            ->pluck('opened', 'month');

        $start = now()->startOfMonth()->subMonths(11);

        // Everything opened BEFORE the window still counts toward the running total, or the first
        // point would read as though the platform had just started.
        $running = (int) Tenant::query()->where('created_at', '<', $start)->count();

        $series = [];
        for ($i = 0; $i < 12; $i++) {
            $month = $start->copy()->addMonths($i)->format('Y-m');
            $opened = (int) ($counts[$month] ?? 0);
            $running += $opened;
            $series[] = ['month' => $month, 'opened' => $opened, 'total' => $running];
        }

        return $series;
    }

    /**
     * Subscription health, and what the platform has been PROMISED — never what it has collected.
     *
     * `committed_monthly` is the sum of what active subscriptions are worth per month, normalised
     * from annual terms. It is not revenue: CampaignsHub does not yet charge tenants, and the
     * invoices ledger in this database is agency-to-client billing, which belongs to the agency.
     * `collection_status` says so in the payload rather than leaving the figure to be read as money
     * in the bank.
     *
     * @return array<string, mixed>
     */
    private function subscriptions(): array
    {
        /*
         * Priced from the SUBSCRIPTION, not from the plan it names.
         *
         * `subscriptions.unit_amount` is what this tenant actually agreed to pay. Re-deriving it from
         * `subscription_plans` would silently re-price every existing subscription the next time the
         * owner edits a plan — so a price change would appear to have applied retroactively to
         * customers who are still on the old terms.
         */
        $rows = DB::table('subscriptions')
            ->get(['status', 'billing_interval', 'currency', 'unit_amount']);

        $byStatus = $rows->groupBy('status')->map->count()->all();

        $committed = $rows
            ->filter(fn ($r) => in_array($r->status, ['active', 'trialing'], true))
            ->groupBy(fn ($r) => $r->currency ?? 'SAR')
            ->map(fn ($group, $currency) => [
                'currency' => (string) $currency,
                // Annual terms are normalised to a monthly figure so the total is comparable. The
                // money is not collected monthly and this does not claim it is.
                'monthly' => round($group->sum(fn ($r) => $r->billing_interval === 'yearly'
                    ? ((float) ($r->unit_amount ?? 0)) / 12
                    : (float) ($r->unit_amount ?? 0)), 2),
                'subscriptions' => $group->count(),
            ])
            ->values()->all();

        return [
            'by_status' => $byStatus,
            'committed_monthly' => $committed,
            // Said in the payload, so no reader has to know it from somewhere else.
            'collection_status' => 'not_implemented',
        ];
    }

    /**
     * What the owner should look at, and where each one lives.
     *
     * Counts only, each with the route that answers it — so the console leads somewhere rather than
     * reporting a number and leaving the owner to find the page. A zero is returned rather than
     * omitted: "nothing is past due" is information, and a row that vanishes is indistinguishable
     * from a row that was never computed.
     *
     * @return list<array{key: string, count: int, to: string, tone: string}>
     */
    private function attention(): array
    {
        $pendingReview = DB::table('registration_requests')
            ->whereIn('state', ['pending_review', 'info_requested'])->count();

        $pastDue = DB::table('subscriptions')->whereIn('status', ['past_due', 'unpaid'])->count();

        return [
            ['key' => 'registrations_pending', 'count' => (int) $pendingReview, 'to' => '/admin/registrations', 'tone' => 'warning'],
            ['key' => 'subscriptions_past_due', 'count' => (int) $pastDue, 'to' => '/admin/billing', 'tone' => 'danger'],
            ['key' => 'tenants_suspended', 'count' => Tenant::query()->where('status', 'suspended')->count(), 'to' => '/admin/tenants', 'tone' => 'danger'],
            ['key' => 'connections_need_reconnect', 'count' => $this->reconnectQuery()->count(), 'to' => '/admin/tenants', 'tone' => 'warning'],
            ['key' => 'users_without_membership', 'count' => User::query()->where('is_platform_admin', false)
                ->whereDoesntHave('memberships')->count(), 'to' => '/admin/tenants', 'tone' => 'info'],
        ];
    }

    /**
     * SUPERADMIN-CONSOLE-HOME-001 — ad-platform connections that have stopped working.
     *
     * A connection the provider refused (status `error`, set by ProviderSessionInvalidation when the token
     * was invalidated) or whose token has passed its expiry while still marked connected. Both mean the
     * workspace's numbers have stopped updating until its owner signs the account in again — which only
     * the owner can do; the console names it, it does not reconnect it.
     */
    private function reconnectQuery()
    {
        return ProviderConnection::query()->where(fn ($q) => $q
            ->where('status', 'error')
            ->orWhere(fn ($q) => $q->where('status', 'connected')->whereNotNull('token_expires_at')->where('token_expires_at', '<', now())));
    }

    /**
     * The provider's error text is deliberately left out: it can carry the customer's account ids, and
     * the tenant's own integrations page already shows it to the people who can act on it.
     *
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private function connectionsNeedingReconnect(): array
    {
        $rows = $this->reconnectQuery()->orderByDesc('updated_at')->limit(10)
            ->get(['id', 'tenant_id', 'provider', 'connection_name', 'status', 'token_expires_at', 'last_health_check_at', 'updated_at']);
        $names = Tenant::query()->whereIn('id', $rows->pluck('tenant_id')->unique())->pluck('name', 'id');

        return [
            'total' => $this->reconnectQuery()->count(),
            'items' => $rows->map(fn (ProviderConnection $c) => [
                'id' => (string) $c->id,
                'tenant_id' => (string) $c->tenant_id,
                'tenant_name' => $names[$c->tenant_id] ?? null,
                'provider' => (string) $c->provider,
                'connection_name' => $c->connection_name,
                'reason' => $c->status === 'error' ? 'refused' : 'expired',
                'since' => ($c->status === 'error' ? ($c->last_health_check_at ?? $c->updated_at) : $c->token_expires_at)?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * Open alerts across every workspace, by severity — counts only. Each alert belongs to its workspace and
     * is handled there; the console shows whether the platform as a whole is quiet.
     *
     * @return array{open: int, by_severity: array<string, int>, tenants: int}
     */
    private function openAlerts(): array
    {
        $open = AlertEvent::query()->where(fn ($q) => $q->where('status', 'open')
            ->orWhere(fn ($q) => $q->where('status', 'snoozed')->where('snoozed_until', '<', now())));

        $bySeverity = (clone $open)->selectRaw('severity, count(*) as n')->groupBy('severity')->pluck('n', 'severity')
            ->map(fn ($n) => (int) $n);

        return [
            'open' => (int) $bySeverity->sum(),
            'by_severity' => [
                'critical' => (int) ($bySeverity['critical'] ?? 0),
                'warning' => (int) ($bySeverity['warning'] ?? 0),
                'info' => (int) ($bySeverity['info'] ?? 0),
            ],
            'tenants' => (int) (clone $open)->distinct()->count('tenant_id'),
        ];
    }

    /**
     * The last ten things done FROM the console — platform changes and support visits. Who, what, where,
     * when; the before/after values stay on the audit page, behind its own filters.
     *
     * @return list<array<string, mixed>>
     */
    private function recentConsoleActivity(): array
    {
        $rows = AuditLog::query()
            ->where(fn ($q) => $q->where('action', 'like', 'platform.%')->orWhere('action', 'like', 'support.%'))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(10)
            ->get(['id', 'tenant_id', 'user_id', 'action', 'created_at']);

        $tenants = Tenant::query()->whereIn('id', $rows->pluck('tenant_id')->filter()->unique())->pluck('name', 'id');
        $users = User::query()->whereIn('id', $rows->pluck('user_id')->filter()->unique())->pluck('name', 'id');

        return $rows->map(fn (AuditLog $a) => [
            'id' => (string) $a->id,
            'action' => (string) $a->action,
            'tenant_name' => $a->tenant_id ? ($tenants[$a->tenant_id] ?? null) : null,
            'user_name' => $a->user_id ? ($users[$a->user_id] ?? null) : null,
            'created_at' => $a->created_at?->toIso8601String(),
        ])->values()->all();
    }
}
