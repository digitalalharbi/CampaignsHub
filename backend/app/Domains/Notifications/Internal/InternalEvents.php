<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Internal;

use App\Domains\Notifications\Engine\Contracts\EventRegistry;
use App\Domains\Notifications\Engine\Data\DeliveryIntent;
use App\Domains\Notifications\Engine\NotificationEngine;
use App\Domains\Notifications\Followup\FollowupTasks;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * NOTIF-V2-INTERNAL-001 — the one place a domain says «this internal fact happened» (spec §3, §5).
 *
 * It does not decide WHO hears it: the shared engine routes by role, project assignment, personal switches and plan
 * (and a client-portal profile never receives a sensitive event). It adds two things the producers should not each
 * repeat: the «active projects only» rule, and the follow-up task an event may open.
 *
 * It never throws into the caller: a notification must not fail the write that caused it.
 */
final class InternalEvents
{
    public function __construct(
        private readonly NotificationEngine $engine,
        private readonly EventRegistry $events,
        private readonly FollowupTasks $tasks,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  scalars the catalog strings use (`:actor`, `:before`, `:after`, `:date`, …)
     * @return array{queued: int, grouped: int, duplicates: int, refused: int, deferred: int}
     */
    public function raise(
        string $eventKey,
        string $tenantId,
        ?string $projectId,
        array $payload = [],
        ?int $actorUserId = null,
        ?string $objectType = null,
        ?string $objectId = null,
        ?string $threshold = null,
    ): array {
        $none = ['queued' => 0, 'grouped' => 0, 'duplicates' => 0, 'refused' => 0, 'deferred' => 0];

        try {
            $event = $this->events->get($eventKey);
            if ($event === null) {
                return $none;
            }
            // «Active projects only» — except the two facts that ARE the project's life cycle.
            $lifecycle = in_array($eventKey, ['project.created', 'project.archived'], true);
            if ($projectId !== null && ! $lifecycle && ! ActiveProjects::isLive($tenantId, $projectId)) {
                return $none;
            }

            if ($actorUserId !== null && ! isset($payload['actor'])) {
                $payload['actor'] = (string) (DB::table('users')->where('id', $actorUserId)->value('name') ?? '');
            }
            $payload['url'] ??= EngineKinds::path($eventKey, $projectId);

            $result = $this->engine->raise(new DeliveryIntent(
                eventKey: $eventKey, tenantId: $tenantId, projectId: $projectId, objectType: $objectType, objectId: $objectId,
                threshold: $threshold, payload: $payload, actorUserId: $actorUserId,
            ));

            if ($event->createsTask) {
                $this->tasks->openForEvent($eventKey, $tenantId, $projectId, $payload, $objectType, $objectId);
            }

            return $result;
        } catch (Throwable $e) {
            report($e);

            return $none;
        }
    }
}
