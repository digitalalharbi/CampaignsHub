<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Http\Controllers;

use App\Domains\AI\Models\AIProviderCredential;
use App\Domains\Subscriptions\Usage\CreditTransaction;
use App\Domains\Subscriptions\Usage\UsageLedger;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PRICING-USAGE-CREDITS-001 — what this workspace has used this month, what it cost, and its credit balance.
 *
 * Usage on the workspace's own AI keys is listed key by key against the cap the workspace set on each key; it is shown as paid
 * to their provider, never as a charge from us.
 */
final class UsageController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly UsageLedger $ledger) {}

    /** GET /api/v1/subscriptions/usage */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('subscriptions.view'), 403);
        $tenantId = (string) $this->context->tenantId();
        $tenant = Tenant::query()->findOrFail($tenantId);

        $keys = AIProviderCredential::query()->where('tenant_id', $tenantId)->where('status', 'active')->get()
            ->map(fn (AIProviderCredential $c) => [
                'id' => (string) $c->getKey(), 'provider' => $c->provider, 'key' => $c->maskedKey(),
                'monthly_token_limit' => $c->monthly_token_limit === null ? null : (int) $c->monthly_token_limit,
                'used' => $this->ledger->usedThisMonth($tenantId, 'ai_tokens_own_key', (string) $c->getKey()),
            ])->values()->all();

        return ApiResponse::success([
            'period' => now()->utc()->format('Y-m'),
            'meters' => $this->ledger->summary($tenant),
            'own_keys' => $keys,
            'credits' => [
                'balance' => $this->ledger->balance($tenantId),
                'currency' => 'SAR',
                'recent' => CreditTransaction::query()->where('tenant_id', $tenantId)->orderByDesc('id')->limit(20)->get()
                    ->map(fn (CreditTransaction $t) => [
                        'kind' => $t->kind, 'amount' => bcadd((string) $t->amount, '0', 2), 'balance_after' => bcadd((string) $t->balance_after, '0', 2),
                        'reason' => $t->kind === 'consume' ? null : $t->reason, 'at' => $t->created_at?->toIso8601String(),
                    ])->values()->all(),
            ],
            // Credits and unit costs are VAT-exclusive, like every price we show.
            'prices_exclude_vat' => true,
        ], 'Usage.');
    }
}
