<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Http\Controllers;

use App\Domains\Integrations\Models\IntegrationWebhookEvent;
use App\Domains\Integrations\Webhooks\WebhookReplayer;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * INTEG-WEBHOOK-ENGINE-001 — the platform owner's view of the webhook inbox, and the one write on it.
 *
 * Behind `platform` (the owner's console), never a tenant route: the inbox spans tenants, and a
 * delivery that matched nothing has no tenant to be scoped to. Rows are shown WITHOUT their payload —
 * the verdict, the state and the reason are what an operator needs to answer «did this delivery do
 * anything?», and the body can carry a merchant's customers.
 */
final class PlatformWebhookEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'string', 'max:24'],
            'verdict' => ['nullable', 'string', 'max:32'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $rows = IntegrationWebhookEvent::query()
            ->when($data['provider'] ?? null, fn ($q, $v) => $q->where('provider', $v))
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['verdict'] ?? null, fn ($q, $v) => $q->where('verdict', $v))
            ->orderByDesc('received_at')
            ->limit((int) ($data['limit'] ?? 50))
            ->get()
            ->map(fn (IntegrationWebhookEvent $e): array => $this->present($e))
            ->all();

        return ApiResponse::success($rows, 'Webhook deliveries.');
    }

    public function replay(Request $request, string $event, WebhookReplayer $replayer): JsonResponse
    {
        $row = IntegrationWebhookEvent::query()->findOrFail($event);

        $result = $replayer->replay($row, $request->user()?->id);

        if ($result['outcome'] === 'not_replayable') {
            return ApiResponse::error('This delivery cannot be replayed.', null, [], 422);
        }

        return ApiResponse::success($result, $result['outcome'] === 'requeued' ? 'Delivery re-queued.' : 'Nothing to replay.');
    }

    /** @return array<string,mixed> */
    private function present(IntegrationWebhookEvent $e): array
    {
        return [
            'id' => $e->getKey(),
            'provider' => $e->provider,
            'kind' => $e->kind,
            'source' => $e->source,
            'topic' => $e->topic,
            'status' => $e->status,
            'verdict' => $e->verdict,
            'signature_verified' => $e->signature_verified,
            'tenant_id' => $e->tenant_id,
            'external_account_id' => $e->external_account_id,
            'received_at' => $e->received_at?->toIso8601String(),
            'event_time' => $e->event_time?->toIso8601String(),
            'processed_at' => $e->processed_at?->toIso8601String(),
            'dead_lettered_at' => $e->dead_lettered_at?->toIso8601String(),
            'attempts' => $e->attempts,
            'duplicate_count' => $e->duplicate_count,
            'replayed_count' => $e->replayed_count,
            'error' => $e->error,
            'correlation_id' => $e->correlation_id,
        ];
    }
}
