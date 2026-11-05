<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\AdminSubscriptionControl;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ADMIN-FULL-CONTROL-001 — one workspace's subscription, on the console. Behind `platform`, like everything in this file's route
 * group. Reads and writes the subscription's terms only: no campaign, client or report data.
 */
final class PlatformTenantSubscriptionController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly SubscriptionService $subscriptions,
        private readonly AdminSubscriptionControl $control,
    ) {}

    /** GET /api/v1/admin/tenants/{tenant}/subscription */
    public function show(Tenant $tenant): JsonResponse
    {
        $this->tenants->enterPlatformScope();

        return ApiResponse::success([
            'subscription' => $this->shape($this->subscriptions->subscriptionFor($tenant)),
            'plans' => SubscriptionPlan::query()->where('is_active', true)->orderBy('sort_order')->get()
                ->map(fn (SubscriptionPlan $p) => [
                    'code' => $p->code,
                    'name' => $p->name,
                    'currency' => $p->currency,
                    'price_monthly' => $p->priceFor('monthly') === null ? null : (string) $p->priceFor('monthly'),
                    'price_annual' => $p->priceFor('annual') === null ? null : (string) $p->priceFor('annual'),
                ])->values()->all(),
            'history' => $this->history($tenant),
        ], 'Subscription.');
    }

    /** POST /api/v1/admin/tenants/{tenant}/subscription */
    public function act(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(AdminSubscriptionControl::ACTIONS)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'plan_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'interval' => ['sometimes', 'nullable', 'string', 'in:monthly,annual'],
            'ends_on' => ['sometimes', 'nullable', 'date'],
            'discount_percent' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:99'],
            'confirm_complimentary' => ['sometimes', 'boolean'],
        ]);

        $this->tenants->enterPlatformScope();

        /** @var User $operator */
        $operator = $request->user();
        $wasPublicPortal = (bool) $tenant->is_default_portal;

        $subscription = $this->control->apply($tenant, $data, $operator);

        return ApiResponse::success([
            'subscription' => $this->shape($subscription->load('plan')),
            'tenant' => ['id' => (string) $tenant->getKey(), 'status' => $tenant->refresh()->status],
            // Said plainly, as on the status switch: the owner may mean it, and should not discover it afterwards.
            'public_intake_affected' => $wasPublicPortal && $tenant->status === 'suspended',
        ], 'Done.');
    }

    /** @return array<string, mixed>|null */
    private function shape(?Subscription $subscription): ?array
    {
        if ($subscription === null) {
            return null;
        }

        return [
            'status' => $subscription->status,
            'plan_code' => $subscription->plan?->code,
            'plan_name' => $subscription->plan?->name,
            'billing_interval' => $subscription->billing_interval,
            'unit_amount' => $subscription->unit_amount === null ? null : (string) $subscription->unit_amount,
            'currency' => $subscription->currency,
            'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'grace_ends_at' => $subscription->grace_ends_at?->toIso8601String(),
            'commercial_basis' => $subscription->commercial_basis,
            'open_ended' => $subscription->isComplimentary(),
            'next_charge' => AdminSubscriptionControl::nextCharge($subscription),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function history(Tenant $tenant): array
    {
        $rows = AuditLog::query()->where('tenant_id', $tenant->getKey())->where('action', 'like', 'platform.subscription.%')
            ->orderByDesc('created_at')->limit(10)->get();
        $names = User::query()->whereIn('id', $rows->pluck('user_id')->filter()->unique())->pluck('name', 'id');

        return $rows->map(fn (AuditLog $l) => [
            'action' => str_replace('platform.subscription.', '', (string) $l->action),
            'at' => $l->created_at?->toIso8601String(),
            'by' => $l->user_id === null ? null : ($names[$l->user_id] ?? null),
            'reason' => $l->reason,
        ])->values()->all();
    }
}
