<?php

declare(strict_types=1);

namespace App\Domains\Delivery\Services;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Delivery\Models\DeliveryPack;
use App\Domains\Tasks\Models\Task;
use Illuminate\Support\Carbon;

/**
 * DELIVERY-TASK-001 — a weekly pack puts ONE piece of work on the tasks board, and the pack's own state closes it.
 *
 * The weekly delivery was a ledger beside the tasks board rather than on it: a pack waiting for review was visible
 * only to someone who opened the client's delivery tab. Preparing a pack now opens a review task bound to that pack
 * by id in `meta`, so the board names the work and its period.
 *
 * Three rules keep that honest. The task is found by the pack's id before one is made, so preparing twice, the
 * scheduler and a person racing, or a re-run never adds a second. It is closed only by the pack being marked sent —
 * which itself requires an approved pack — never by a guess that the week «probably went out». And closing it says
 * what closed it, so a completed task on the board can be traced to the act that completed it.
 */
final class DeliveryTasks
{
    public const SOURCE = 'delivery_pack';

    public static function find(DeliveryPack $pack): ?Task
    {
        return Task::withoutGlobalScopes()
            ->where('tenant_id', $pack->tenant_id)
            ->where('meta->source', self::SOURCE)
            ->where('meta->delivery_pack_id', (string) $pack->getKey())
            ->first();
    }

    /** The review task for this pack — the existing one if there is one. */
    public static function ensureFor(DeliveryPack $pack): Task
    {
        $existing = self::find($pack);
        if ($existing !== null) {
            return $existing;
        }

        $workspace = ClientWorkspace::withoutGlobalScopes()->find($pack->client_workspace_id);
        $period = $pack->period_start->toDateString().' → '.$pack->period_end->toDateString();

        $task = new Task;
        $task->forceFill([
            'tenant_id' => $pack->tenant_id,
            'client_workspace_id' => $pack->client_workspace_id,
            'project_id' => $pack->project_id,
            'created_by' => $pack->created_by,
            'assignee_id' => $workspace === null ? null : DeliverySettings::for($workspace)['reviewer_id'],
            'title' => 'مراجعة واعتماد التسليم الأسبوعي — '.($workspace->name ?? '').' ('.$period.')',
            'description' => 'راجع التقرير والتوصيات واعتمد الحزمة قبل إرسالها للعميل. تُغلق هذه المهمة تلقائيًا عند تسجيل الإرسال فقط.',
            'status' => 'todo',
            'priority' => 'high',
            'due_date' => $pack->period_end->copy()->addDay()->toDateString(),
            'meta' => ['source' => self::SOURCE, 'delivery_pack_id' => (string) $pack->getKey()],
        ])->save();

        return $task;
    }

    /** The pack was marked sent: complete its task, saying so. A task someone already closed is left as they left it. */
    public static function closeFor(DeliveryPack $pack): void
    {
        $task = self::find($pack);
        if ($task === null || in_array($task->status, ['completed', 'cancelled'], true)) {
            return;
        }

        $meta = (array) ($task->meta ?? []);
        $meta['closed_by'] = 'delivery_pack.sent';
        $meta['closed_at'] = Carbon::now()->toIso8601String();
        $task->forceFill(['status' => 'completed', 'meta' => $meta])->save();
    }
}
