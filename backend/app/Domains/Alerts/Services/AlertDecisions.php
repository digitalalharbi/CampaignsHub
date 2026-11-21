<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Services;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertEventDecision;
use App\Domains\Notifications\Services\DigestScope;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * OPERATIONAL-INTELLIGENCE-P1-001 — the decision log: status, owner, note — each change one append-only row.
 */
final class AlertDecisions
{
    public const STATUSES = ['new', 'in_progress', 'done', 'dismissed'];

    public const ACTION_TAGS = ['increase_budget', 'decrease_budget', 'pause_ad', 'change_content', 'adjust_audience', 'fix_tracking', 'reconnect_account', 'other'];

    public function __construct(private readonly AlertEvaluator $evaluator, private readonly AlertImpact $impact, private readonly DigestScope $scope) {}

    public function setStatus(AlertEvent $event, User $by, string $status, ?string $reason, ?string $tag, ?string $note, ?Carbon $now = null): AlertEvent
    {
        $now ??= Carbon::now();

        return DB::transaction(function () use ($event, $by, $status, $reason, $tag, $note, $now): AlertEvent {
            $event = AlertEvent::query()->lockForUpdate()->findOrFail($event->getKey());
            $from = $event->decision_status ?? 'new';

            $event->forceFill([
                'decision_status' => $status,
                'decision_reason' => $status === 'dismissed' ? $reason : null,
                'action_tag' => $status === 'done' ? $tag : null,
                'decided_at' => $now,
                'impact_baseline' => $status === 'done' ? $this->impact->baseline($event, $tag, $now) : null,
            ])->save();

            if (in_array($status, ['done', 'dismissed'], true)) {
                $this->evaluator->resolve($event, $now);
            } elseif ($event->status === 'resolved') {
                $event->forceFill(['status' => 'open', 'resolved_at' => null])->save();
            }

            $this->log($event, $by, 'status', ['from_status' => $from, 'to_status' => $status, 'action_tag' => $status === 'done' ? $tag : null, 'note' => $status === 'dismissed' ? trim($reason."\n".$note) : $note], $now);

            return $event->refresh();
        });
    }

    public function assign(AlertEvent $event, User $by, ?int $ownerId, ?string $note, ?Carbon $now = null): AlertEvent
    {
        $now ??= Carbon::now();

        return DB::transaction(function () use ($event, $by, $ownerId, $note, $now): AlertEvent {
            $event = AlertEvent::query()->lockForUpdate()->findOrFail($event->getKey());
            $from = $event->owner_id === null ? null : (int) $event->owner_id;
            $event->forceFill(['owner_id' => $ownerId])->save();
            $this->log($event, $by, 'assign', ['from_owner_id' => $from, 'to_owner_id' => $ownerId, 'note' => $note], $now);

            return $event->refresh();
        });
    }

    public function note(AlertEvent $event, User $by, string $note, ?Carbon $now = null): void
    {
        $this->log($event, $by, 'note', ['note' => $note], $now ?? Carbon::now());
    }

    /** The lifecycle buttons that already existed (resolve / snooze) leave a line too. */
    public function lifecycle(AlertEvent $event, User $by, string $kind, ?string $note = null): void
    {
        $this->log($event, $by, $kind, ['to_status' => $event->status, 'note' => $note], Carbon::now());
    }

    /**
     * Who may own this alert: an ACTIVE member of this tenant who can see alerts and whose own reach contains the
     * alert's project. Fail-closed — no project reach, no ownership.
     */
    public function mayOwn(AlertEvent $event, User $candidate): bool
    {
        $tenantId = (string) $event->tenant_id;
        if (! User::query()->inTenant($tenantId)->whereKey($candidate->getKey())->exists()) {
            return false;
        }
        if (! $candidate->hasPermission('alerts.view')) {
            return false;
        }

        return $event->project_id === null || in_array((string) $event->project_id, $this->scope->projectIdsFor($candidate, $tenantId), true);
    }

    /** @return list<array{id:int,name:string}> */
    public function candidates(AlertEvent $event): array
    {
        return User::query()->inTenant((string) $event->tenant_id)->orderBy('name')->limit(200)->get()
            ->filter(fn (User $u): bool => $this->mayOwn($event, $u))
            ->map(static fn (User $u): array => ['id' => (int) $u->id, 'name' => (string) $u->name])->values()->all();
    }

    /** @param array<string,mixed> $fields */
    private function log(AlertEvent $event, User $by, string $kind, array $fields, Carbon $now): void
    {
        AlertEventDecision::create($fields + [
            'tenant_id' => $event->tenant_id, 'alert_event_id' => $event->getKey(), 'user_id' => $by->getKey(), 'kind' => $kind, 'created_at' => $now,
        ]);
    }
}
