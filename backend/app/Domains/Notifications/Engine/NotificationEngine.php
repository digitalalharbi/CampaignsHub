<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine;

use App\Domains\Notifications\Engine\Contracts\DeliveryLog;
use App\Domains\Notifications\Engine\Contracts\EventRegistry;
use App\Domains\Notifications\Engine\Contracts\Router;
use App\Domains\Notifications\Engine\Data\Delivery;
use App\Domains\Notifications\Engine\Data\DeliveryIntent;
use App\Domains\Notifications\Engine\Enums\DeliveryStatus;
use App\Domains\Notifications\Engine\Enums\Priority;
use App\Domains\Notifications\Engine\Workspace\WorkspaceSettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single entry point of the shared engine (spec §3): one event → several deliveries.
 *
 * `raise()` routes the intent and writes one outbox row per (recipient, channel):
 *
 * - **De-dup**: `dedup_key` = event + object + threshold + day (workspace timezone) + recipient + channel; unique in the
 *   outbox, so the same fact on the same day is one delivery however often a sweep re-raises it.
 * - **Grouping**: a groupable info/important event waits `grouping_window_minutes`; another event of the same type for
 *   the same recipient, channel and project inside that window joins the waiting row (`group_count`, `_objects`)
 *   instead of creating a second delivery. Critical events never wait.
 * - **Refusals** (`blocked_by_plan`) are written as a finished outbox row + one log row, de-duplicated the same way.
 * - **Deferral** (quiet hours / holidays) sets `not_before` and logs one `deferred` row.
 *
 * Nothing is sent here. `SenderPipeline` sends.
 */
final class NotificationEngine
{
    public function __construct(
        private readonly Router $router,
        private readonly EventRegistry $events,
        private readonly WorkspaceSettingsRepository $settings,
        private readonly DeliveryLog $log,
    ) {}

    /** @return array{queued: int, grouped: int, duplicates: int, refused: int, deferred: int} */
    public function raise(DeliveryIntent $intent): array
    {
        $out = ['queued' => 0, 'grouped' => 0, 'duplicates' => 0, 'refused' => 0, 'deferred' => 0];
        $event = $this->events->get($intent->eventKey);
        if ($event === null) {
            return $out;
        }

        $ws = $this->settings->for($intent->tenantId);
        $base = $intent->dedupBase($ws->timezone);
        $projectName = $intent->projectId === null ? null
            : DB::table('projects')->where('id', $intent->projectId)->where('tenant_id', $intent->tenantId)->value('name');
        $window = (int) ($ws->settings['grouping_window_minutes'] ?? config('notifications_v2.routing.grouping_window_minutes', 10));
        $now = Carbon::now();

        foreach ($this->router->route($intent) as $decision) {
            $dedup = 'd:'.sha1($base.'|'.$decision->recipient->identity().'|'.$decision->channel->value);
            if ($this->isDuplicate($intent->tenantId, $dedup)) {
                $out['duplicates']++;

                continue;
            }

            $payload = array_merge($intent->payload, [
                'project_name' => $intent->payload['project_name'] ?? $projectName,
                'workspace_name' => $intent->payload['workspace_name'] ?? $ws->workspaceName,
                '_locale' => $decision->recipient->locale,
                '_role' => $decision->recipient->roleKey,
                '_objects' => [$this->object($intent)],
                '_dedup' => [$dedup],
            ]);

            $groups = $decision->delivers() && $event->groupable && $decision->priority !== Priority::Critical && $window > 0;
            $groupKey = $groups ? 'g:'.sha1(implode('|', [$intent->eventKey, $decision->recipient->identity(), $decision->channel->value, $intent->projectId ?? '-'])) : null;

            if ($groupKey !== null && $this->joinGroup($intent->tenantId, $groupKey, $dedup, $this->object($intent))) {
                $out['grouped']++;

                continue;
            }

            $notBefore = $decision->notBefore;
            if ($groupKey !== null) {
                $windowEnd = $now->copy()->addMinutes($window);
                $notBefore = $notBefore === null || $notBefore->lt($windowEnd) ? $windowEnd : $notBefore;
            }

            $row = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $intent->tenantId,
                'reference' => self::reference(),
                'event_key' => $intent->eventKey,
                'scope' => $decision->scope->value,
                'channel' => $decision->channel->value,
                'priority' => $decision->priority->value,
                'recipient_kind' => $decision->recipient->kind->value,
                'recipient_user_id' => $decision->recipient->userId,
                'recipient_external_id' => $decision->recipient->externalId,
                'subscription_id' => $decision->subscriptionId ?? $intent->subscriptionId,
                'recipient_address' => $decision->recipient->address,
                'project_id' => $intent->projectId,
                'dedup_key' => $dedup,
                'group_key' => $groupKey,
                'group_count' => 1,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'content_version' => $intent->contentVersion,
                'state' => $decision->delivers() ? 'pending' : 'done',
                'attempts' => 0,
                'not_before' => $notBefore,
                'deferred_reason' => $decision->deferredReason,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (DB::table('notification_outbox')->insertOrIgnore($row) === 0) {
                $out['duplicates']++;

                continue;
            }

            if (! $decision->delivers()) {
                $this->log->record(Delivery::fromRow((object) $row), $decision->refusal, $decision->reason);
                $out['refused']++;

                continue;
            }
            if ($decision->deferredReason !== null) {
                $this->log->record(Delivery::fromRow((object) $row), DeliveryStatus::Deferred, $decision->deferredReason);
                $out['deferred']++;
            }
            $out['queued']++;
        }

        return $out;
    }

    /** A short human reference, printed on the message and on every log row: N-YYMMDD-XXXXXXXX. */
    public static function reference(): string
    {
        return 'N-'.Carbon::now()->format('ymd').'-'.strtoupper(Str::random(8));
    }

    private function isDuplicate(string $tenantId, string $dedup): bool
    {
        return DB::table('notification_outbox')->where('dedup_key', $dedup)->exists()
            || DB::table('notification_outbox')->where('tenant_id', $tenantId)->where('state', 'pending')
                ->whereRaw("jsonb_exists(payload->'_dedup', ?)", [$dedup])->exists();
    }

    /** @param array<string, mixed> $object */
    private function joinGroup(string $tenantId, string $groupKey, string $dedup, array $object): bool
    {
        return DB::transaction(function () use ($tenantId, $groupKey, $dedup, $object): bool {
            $row = DB::table('notification_outbox')->where('tenant_id', $tenantId)->where('group_key', $groupKey)
                ->where('state', 'pending')->where('attempts', 0)->lockForUpdate()->orderBy('created_at')->first();
            if ($row === null) {
                return false;
            }

            $payload = (array) json_decode((string) $row->payload, true);
            $payload['_objects'] = array_merge((array) ($payload['_objects'] ?? []), [$object]);
            $payload['_dedup'] = array_merge((array) ($payload['_dedup'] ?? []), [$dedup]);

            DB::table('notification_outbox')->where('id', $row->id)->update([
                'group_count' => (int) $row->group_count + 1,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'updated_at' => Carbon::now(),
            ]);

            return true;
        });
    }

    /** @return array<string, mixed> */
    private function object(DeliveryIntent $intent): array
    {
        return ['type' => $intent->objectType, 'id' => $intent->objectId, 'threshold' => $intent->threshold, 'at' => $intent->at()->toIso8601String()];
    }
}
