<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\AI\Models\PlatformAiKey;
use App\Domains\Audit\AuditLogger;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Usage\UsageEvent;
use App\Domains\Subscriptions\Usage\UsageMeter;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI-PLATFORM-KEY-001 — the platform's AI key and the per-person allowance, in the admin console.
 *
 * Write-only secrets: a key is stored encrypted and only its last four characters ever come back. Saving a key, switching it on
 * and changing the allowance are each audited without the secret. The allowance is the monthly number of assistant replies ONE
 * person gets on the platform key, per plan; empty = not capped.
 */
final class PlatformAiKeyController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** GET /api/v1/admin/settings/ai */
    public function index(): JsonResponse
    {
        return ApiResponse::success($this->payload(), 'Platform AI.');
    }

    /** PUT /api/v1/admin/settings/ai/{provider} — {secret?: string, enabled?: bool} */
    public function save(Request $request, string $provider): JsonResponse
    {
        abort_unless(in_array($provider, PlatformAiKey::ORDER, true), 404);
        $data = $request->validate([
            'secret' => ['sometimes', 'nullable', 'string', 'min:20', 'max:400'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $key = PlatformAiKey::query()->firstOrNew(['provider' => $provider]);
        $changed = [];
        if (is_string($data['secret'] ?? null) && trim($data['secret']) !== '') {
            $key->setSecret(trim($data['secret']));
            $changed[] = 'secret';
        }
        if (array_key_exists('enabled', $data)) {
            abort_if((bool) $data['enabled'] && $key->last_four === null, 422, 'secret_required');
            $key->enabled = (bool) $data['enabled'];
            $changed[] = 'enabled';
        }
        $key->configured_by = $request->user()?->getKey();
        $key->save();

        // The audit never carries the secret: which fields changed, and the masked hint.
        $this->audit->log(action: 'platform.ai_key.updated', entityType: PlatformAiKey::class, entityId: $provider,
            after: ['fields_changed' => $changed, 'key' => $key->maskedKey(), 'enabled' => $key->enabled]);

        return ApiResponse::success($this->payload(), 'Saved.');
    }

    /** PUT /api/v1/admin/settings/ai/allowance — {plans: {plan_code: int|null}} */
    public function allowance(Request $request): JsonResponse
    {
        $codes = SubscriptionPlan::query()->pluck('code')->map(fn ($c) => (string) $c)->all();
        $data = $request->validate([
            'plans' => ['required', 'array'],
            'plans.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        foreach (array_keys($data['plans']) as $code) {
            abort_unless(in_array((string) $code, $codes, true), 422, 'unknown_plan');
        }

        $meter = UsageMeter::query()->where('code', 'ai_messages_platform')->firstOrFail();
        $before = $meter->included_per_plan;
        $next = array_filter(
            array_map(static fn ($v) => $v === null ? null : (int) $v, $data['plans']),
            static fn ($v) => $v !== null,
        );
        $meter->included_per_plan = $next === [] ? null : $next;
        $meter->save();

        $this->audit->log(action: 'platform.ai_allowance.updated', entityType: UsageMeter::class, entityId: 'ai_messages_platform',
            before: ['plans' => $before], after: ['plans' => $meter->included_per_plan]);

        return ApiResponse::success($this->payload(), 'Saved.');
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $rows = PlatformAiKey::query()->get()->keyBy('provider');
        $active = PlatformAiKey::active();
        $meter = UsageMeter::query()->where('code', 'ai_messages_platform')->first();
        $start = now()->utc()->startOfMonth();
        $sum = static fn (string $code): int => (int) UsageEvent::query()->where('meter_code', $code)->where('occurred_at', '>=', $start)->sum('quantity');

        return [
            'providers' => array_map(static function (string $p) use ($rows): array {
                $row = $rows->get($p);

                $configured = $row !== null && $row->last_four !== null;

                return [
                    'provider' => $p,
                    'configured' => $configured,
                    'key' => $configured ? $row?->maskedKey() : null,
                    'enabled' => (bool) ($row->enabled ?? false),
                    'updated_at' => $row?->updated_at?->toIso8601String(),
                ];
            }, PlatformAiKey::ORDER),
            'active_provider' => $active?->provider,
            'allowance' => [
                'unit' => 'message',
                'per' => 'person_month',
                'plans' => SubscriptionPlan::query()->orderBy('sort_order')->get(['code', 'name', 'name_ar'])->map(static fn ($plan): array => [
                    'code' => (string) $plan->code, 'name' => (string) $plan->name, 'name_ar' => (string) $plan->name_ar,
                    'messages' => $meter?->includedFor((string) $plan->code),
                ])->values()->all(),
            ],
            'this_month' => ['messages' => $sum('ai_messages_platform'), 'tokens' => $sum('ai_tokens_platform')],
        ];
    }
}
