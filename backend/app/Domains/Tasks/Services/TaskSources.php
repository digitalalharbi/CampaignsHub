<?php

declare(strict_types=1);

namespace App\Domains\Tasks\Services;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Delivery\Models\DeliveryPack;
use App\Domains\Delivery\Services\DeliveryTasks;
use App\Domains\Tasks\Models\Task;
use App\Models\User;

/**
 * OPS-LINK-001 — what each task came from and who holds it, for a whole page of tasks in a fixed number of queries.
 *
 * The source is read from what is STORED, never guessed from a title: the alert event that recorded this task's id
 * (an alert rule with «create a task» opened it), else the `meta.source` the creating code wrote, else «manual». The
 * alert lookup is bound to the task's own tenant, so a task can only ever point at its own workspace's alert.
 */
final class TaskSources
{
    /** @param  iterable<Task>  $tasks */
    public static function attach(iterable $tasks): void
    {
        $list = [];
        foreach ($tasks as $task) {
            $list[] = $task;
        }
        if ($list === []) {
            return;
        }

        $ids = array_map(static fn (Task $t): string => (string) $t->getKey(), $list);
        $tenantId = (string) $list[0]->tenant_id;

        $events = AlertEvent::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('task_id', $ids)
            ->get(['id', 'task_id', 'rule_id', 'type', 'severity', 'status', 'context'])
            ->keyBy(fn (AlertEvent $e): string => (string) $e->task_id);

        $packIds = array_values(array_filter(array_map(static function (Task $t): ?string {
            $meta = (array) ($t->meta ?? []);

            return ($meta['source'] ?? null) === DeliveryTasks::SOURCE && is_string($meta['delivery_pack_id'] ?? null) ? $meta['delivery_pack_id'] : null;
        }, $list)));
        $packs = $packIds === [] ? collect() : DeliveryPack::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $packIds)
            ->get(['id', 'client_workspace_id', 'status', 'period_start', 'period_end'])
            ->keyBy(fn (DeliveryPack $p): string => (string) $p->getKey());

        $userIds = array_values(array_unique(array_filter(array_merge(
            array_map(static fn (Task $t) => $t->assignee_id, $list),
            array_map(static fn (Task $t) => $t->created_by, $list),
        ))));
        $names = $userIds === [] ? collect() : User::query()->whereIn('id', $userIds)->pluck('name', 'id');

        foreach ($list as $task) {
            $event = $events->get((string) $task->getKey());
            $meta = (array) ($task->meta ?? []);

            $task->setAttribute('source_link', match (true) {
                $event !== null => [
                    'kind' => 'alert',
                    'id' => (string) $event->getKey(),
                    'title' => (string) (((array) $event->context)['title'] ?? $event->type),
                    'severity' => $event->severity,
                    'status' => $event->status,
                ],
                ($pack = $packs->get((string) ($meta['delivery_pack_id'] ?? ''))) !== null => [
                    'kind' => 'delivery',
                    'id' => (string) $pack->getKey(),
                    'client_workspace_id' => (string) $pack->client_workspace_id,
                    'status' => $pack->status,
                    'period' => $pack->period_start->toDateString().' → '.$pack->period_end->toDateString(),
                ],
                isset($meta['source']) && is_string($meta['source']) => ['kind' => $meta['source']],
                default => ['kind' => 'manual'],
            });
            $task->setAttribute('assignee_name', $task->assignee_id === null ? null : ($names[$task->assignee_id] ?? null));
            $task->setAttribute('created_by_name', $task->created_by === null ? null : ($names[$task->created_by] ?? null));
        }
    }
}
