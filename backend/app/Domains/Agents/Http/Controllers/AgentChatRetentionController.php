<?php

declare(strict_types=1);

namespace App\Domains\Agents\Http\Controllers;

use App\Domains\Agents\Services\AgentChatRetention;
use App\Domains\Audit\AuditLogger;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** AGENT-CHAT-RETENTION-001 — read and set how long this workspace keeps agent conversations. A limit; there is no «forever». */
final class AgentChatRetentionController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.view'), 403);

        return ApiResponse::success($this->shape(), 'Chat retention.');
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('ai.manage'), 403);
        $data = $request->validate(['days' => ['required', 'integer', 'min:'.AgentChatRetention::MIN, 'max:'.AgentChatRetention::MAX]]);

        $tenant = Tenant::query()->findOrFail((string) $this->tenant->tenantId());
        $before = AgentChatRetention::forTenant((string) $tenant->getKey());
        $after = AgentChatRetention::set($tenant, (int) $data['days']);
        $audit->log(action: 'agent.chat_retention.updated', entityType: Tenant::class, entityId: (string) $tenant->getKey(), before: ['days' => $before], after: ['days' => $after]);

        return ApiResponse::success($this->shape(), 'Chat retention saved.');
    }

    /** @return array{days:int,min:int,max:int,default:int} */
    private function shape(): array
    {
        return ['days' => AgentChatRetention::forTenant($this->tenant->tenantId()), 'min' => AgentChatRetention::MIN, 'max' => AgentChatRetention::MAX, 'default' => AgentChatRetention::default()];
    }
}
