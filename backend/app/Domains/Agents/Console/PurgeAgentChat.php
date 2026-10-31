<?php

declare(strict_types=1);

namespace App\Domains\Agents\Console;

use App\Domains\Agents\Models\AgentMessage;
use App\Domains\Agents\Models\AgentThread;
use App\Domains\Agents\Services\AgentChatRetention;
use App\Domains\Ops\Services\ScheduledRunRows;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AGENT-CHAT-PRIVACY-001 — conversations are not kept forever. A thread whose last message is older than the retention
 * (`agents.chat_retention_days`, 90 by default, shown to people on the chat screen) is deleted with its messages. A thread being
 * answered is never touched. Decisions about proposals live in their own tables and are untouched.
 */
final class PurgeAgentChat extends Command
{
    protected $signature = 'agents:purge-chat {--now= : Treat this ISO time as now}';

    protected $description = 'Delete agent conversations older than the retention period.';

    public function handle(): int
    {
        $now = $this->option('now') !== null ? Carbon::parse((string) $this->option('now')) : Carbon::now();
        $deleted = 0;
        // Each workspace keeps conversations for ITS own retention; a threads' tenant decides, never another's choice.
        foreach (Tenant::withoutGlobalScopes()->pluck('id') as $tenantId) {
            $cutoff = $now->copy()->subDays(AgentChatRetention::forTenant((string) $tenantId));
            $ids = AgentThread::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('updated_at', '<', $cutoff)->where('status', '!=', 'thinking')->pluck('id');
            foreach ($ids->chunk(200) as $chunk) {
                DB::transaction(function () use ($chunk, &$deleted): void {
                    AgentMessage::withoutGlobalScopes()->whereIn('thread_id', $chunk)->delete();
                    $deleted += AgentThread::withoutGlobalScopes()->whereIn('id', $chunk)->delete();
                });
            }
        }

        app(ScheduledRunRows::class)->report($deleted);
        $this->line(sprintf('  %d conversation(s) past their workspace\'s retention deleted.', $deleted));

        return self::SUCCESS;
    }
}
