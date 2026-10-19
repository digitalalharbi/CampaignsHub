<?php

declare(strict_types=1);

namespace App\Domains\Agents\Jobs;

use App\Domains\Agents\Models\AgentThread;
use App\Domains\Agents\Services\AgentConversation;
use App\Domains\Tenancy\Context\TenantContext;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** One agent reply, on the queue: a turn with several tool rounds outlasts a web request. */
final class RunAgentTurnJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(private readonly string $threadId, private readonly ?int $userId) {}

    public function handle(AgentConversation $conversation, TenantContext $tenant): void
    {
        $thread = AgentThread::withoutGlobalScopes()->find($this->threadId);
        if ($thread === null) {
            return;
        }

        $tenant->setTenantId((string) $thread->tenant_id);
        $user = $this->userId === null ? null : User::query()->find($this->userId);

        try {
            $conversation->reply($thread, $user);
            $thread->forceFill(['status' => 'idle', 'last_error' => null])->save();
        } catch (Throwable $e) {
            // A named reason (no_ai_credential) is stored for the screen; anything else is a fault, logged
            // in full and shown only as a code, so no provider or database text reaches the page.
            $named = preg_match('/^[a-z][a-z0-9_]{2,60}$/', $e->getMessage()) === 1;
            if (! $named) {
                report($e);
            }
            $thread->forceFill(['status' => 'failed', 'last_error' => $named ? $e->getMessage() : 'agent_turn_failed'])->save();
        }
    }
}
