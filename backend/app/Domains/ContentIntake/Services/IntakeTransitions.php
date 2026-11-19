<?php

declare(strict_types=1);

namespace App\Domains\ContentIntake\Services;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\ContentIntake\Models\ContentIntakeEvent;
use App\Domains\ContentIntake\Models\ContentIntakeItem;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Notifications\Internal\InternalEvents;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CONTENT-INTAKE-001 — the ONE function that changes an item's status.
 *
 * Nothing else writes `status`. Every move checks, in this order: the item is in a state the action may start from,
 * the actor holds the permission the action needs (the existing catalogue: content.create / update / approve /
 * reject — the workspace owner holds them all, anyone else only by grant), the decision carries its reason, the
 * pre-checks allow submission, and a person does not approve what they uploaded unless they are the workspace owner
 * (`settings.manage`). The row is locked while it is read, the move and its audit line commit together, and the
 * uploader or approvers are notified only after the commit, through the internal notification engine.
 *
 * The audit line is a snapshot — actor name, item name, both states, reason and notes — and is never rewritten.
 */
final class IntakeTransitions
{
    /** action => [from states, to state, permissions (any of), reason required] */
    private const ACTIONS = [
        'submit' => [['draft', 'needs_changes'], 'in_review', ['content.create', 'content.update'], false],
        'approve' => [['in_review'], 'approved', ['content.approve'], false],
        'reject' => [['in_review'], 'rejected', ['content.reject'], true],
        'request_changes' => [['in_review', 'approved'], 'needs_changes', ['content.approve', 'content.reject'], true],
        'reopen' => [['rejected'], 'draft', ['content.create', 'content.update'], false],
        'mark_live' => [['approved'], 'live', ['content.update', 'content.approve'], false],
    ];

    public function __construct(private readonly InternalEvents $events) {}

    /** @return list<string> */
    public static function actions(): array
    {
        return array_keys(self::ACTIONS);
    }

    /**
     * Actions this user could take on this item right now (for the UI; the server re-checks on apply).
     *
     * @return list<string>
     */
    public function available(ContentIntakeItem $item, User $actor): array
    {
        $out = [];
        foreach (self::ACTIONS as $action => [$from, , $perms]) {
            if (! in_array($item->status, $from, true) || ! array_any($perms, static fn (string $p): bool => $actor->hasPermission($p))) {
                continue;
            }
            if ($action === 'approve' && $this->isSelfApproval($item, $actor)) {
                continue;
            }
            if ($action === 'submit' && $item->check_result === 'fail') {
                continue;
            }
            $out[] = $action;
        }

        return $out;
    }

    /**
     * @param  array{reason?: ?string, notes?: ?string, creative_id?: ?string}  $input
     */
    public function apply(ContentIntakeItem $item, string $action, User $actor, array $input = []): ContentIntakeItem
    {
        $def = self::ACTIONS[$action] ?? null;
        if ($def === null) {
            throw new IntakeTransitionException('unknown_action', 422);
        }
        [$from, $to, $perms, $reasonRequired] = $def;

        $result = DB::transaction(function () use ($item, $action, $actor, $input, $from, $to, $perms, $reasonRequired): ContentIntakeItem {
            /** @var ContentIntakeItem $row */
            $row = ContentIntakeItem::query()->lockForUpdate()->findOrFail($item->getKey());

            if (! array_any($perms, static fn (string $p): bool => $actor->hasPermission($p))) {
                throw new IntakeTransitionException('forbidden', 403);
            }
            if (! in_array($row->status, $from, true)) {
                throw new IntakeTransitionException('not_allowed_from_state', 409, ['status' => $row->status, 'action' => $action]);
            }
            $reason = trim((string) ($input['reason'] ?? ''));
            if ($reasonRequired && $reason === '') {
                throw new IntakeTransitionException('reason_required', 422);
            }
            if ($action === 'approve' && $this->isSelfApproval($row, $actor)) {
                throw new IntakeTransitionException('self_approval', 403);
            }
            if ($action === 'submit' && ($row->analyzed_at === null || $row->check_result === 'fail')) {
                throw new IntakeTransitionException('checks_failed', 422, ['failed' => $this->failed($row)]);
            }
            if ($action === 'submit' && ! $this->isUploaderOrManager($row, $actor)) {
                throw new IntakeTransitionException('forbidden', 403);
            }

            $creativeId = null;
            if ($action === 'mark_live' && ! empty($input['creative_id'])) {
                $creativeId = $this->creativeOf($row, (string) $input['creative_id']);
            }

            $before = $row->status;
            $row->status = $to;
            $now = Carbon::now();
            match ($action) {
                'submit' => $row->forceFill(['submitted_at' => $now, 'decided_by' => null, 'decider_name' => null, 'decided_at' => null, 'decision_reason' => null, 'decision_notes' => null]),
                'reopen' => $row->forceFill(['submitted_at' => null, 'decided_by' => null, 'decider_name' => null, 'decided_at' => null, 'decision_reason' => null, 'decision_notes' => null]),
                'mark_live' => $row->forceFill(['launched_at' => $now, 'linked_creative_id' => $creativeId]),
                default => $row->forceFill([
                    'decided_by' => $actor->getKey(), 'decider_name' => $actor->name, 'decided_at' => $now,
                    'decision_reason' => $reason === '' ? null : $reason,
                    'decision_notes' => ($n = trim((string) ($input['notes'] ?? ''))) === '' ? null : $n,
                ]),
            };
            $row->save();

            $this->record($row, $action, $actor, $before, $to, $reason === '' ? null : $reason, ($n = trim((string) ($input['notes'] ?? ''))) === '' ? null : $n);

            return $row;
        });

        $this->notify($result, $action, $actor);

        return $result;
    }

    /**
     * The only move nobody asks for: the source file in Drive changed after the item went to review or was approved.
     * It goes back to «needs changes» so the decision is taken again on what the file is NOW; the trail says why.
     */
    public function sourceChanged(ContentIntakeItem $item): ContentIntakeItem
    {
        return DB::transaction(function () use ($item): ContentIntakeItem {
            /** @var ContentIntakeItem $row */
            $row = ContentIntakeItem::query()->lockForUpdate()->findOrFail($item->getKey());
            $before = $row->status;
            if (in_array($before, ['in_review', 'approved'], true)) {
                $row->forceFill(['status' => 'needs_changes', 'decided_by' => null, 'decider_name' => null, 'decided_at' => null,
                    'decision_reason' => null, 'decision_notes' => null])->save();
            }
            $this->record($row, 'source_changed', null, $before, $row->status, null, null, ['drive_modified_time' => $row->drive_modified_time?->toIso8601String()]);

            return $row;
        });
    }

    /**
     * Append one line to the decision trail. Public so creation, a re-check and a Drive source change write the same shape.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function record(ContentIntakeItem $item, string $action, ?User $actor, ?string $from, ?string $to, ?string $reason = null, ?string $notes = null, ?array $meta = null): ContentIntakeEvent
    {
        return ContentIntakeEvent::query()->create([
            'tenant_id' => $item->tenant_id,
            'item_id' => $item->getKey(),
            'actor_user_id' => $actor?->getKey(),
            'actor_name' => $actor?->name,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'notes' => $notes,
            'item_name' => $item->name,
            'meta' => $meta,
        ]);
    }

    private function isSelfApproval(ContentIntakeItem $item, User $actor): bool
    {
        return $item->uploaded_by !== null && (int) $item->uploaded_by === (int) $actor->getKey() && ! $actor->hasPermission('settings.manage');
    }

    private function isUploaderOrManager(ContentIntakeItem $item, User $actor): bool
    {
        return ($item->uploaded_by !== null && (int) $item->uploaded_by === (int) $actor->getKey())
            || $actor->hasPermission('content.update');
    }

    /** @return list<array{key: string, reason_en: string, reason_ar: string}> */
    private function failed(ContentIntakeItem $item): array
    {
        return array_values(array_map(
            static fn (array $c): array => ['key' => (string) $c['key'], 'reason_en' => (string) $c['reason_en'], 'reason_ar' => (string) $c['reason_ar']],
            array_filter((array) $item->checks, static fn (array $c): bool => ($c['status'] ?? null) === 'fail'),
        ));
    }

    private function creativeOf(ContentIntakeItem $item, string $creativeId): string
    {
        $ok = BoundAccountVisibility::apply(ExternalCreative::query(), 'external_creatives')->where('id', $creativeId)->where('project_id', $item->project_id)->exists();
        if (! $ok) {
            throw new IntakeTransitionException('creative_not_in_project', 422);
        }

        return $creativeId;
    }

    private function notify(ContentIntakeItem $item, string $action, User $actor): void
    {
        if ($action === 'submit') {
            $this->events->raise('content.submitted', $item->tenant_id, $item->project_id, ['item' => $item->name], (int) $actor->getKey(), 'content_intake_item', (string) $item->getKey());

            return;
        }
        if (in_array($action, ['approve', 'reject', 'request_changes'], true) && $item->uploaded_by !== null) {
            $this->events->raise('content.decided', $item->tenant_id, $item->project_id, [
                'item' => $item->name,
                'variant' => $action,
                'addressee_user_id' => (int) $item->uploaded_by,
            ], (int) $actor->getKey(), 'content_intake_item', (string) $item->getKey(), $action);
        }
    }
}
