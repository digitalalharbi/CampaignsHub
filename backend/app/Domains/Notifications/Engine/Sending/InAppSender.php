<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Sending;

use App\Domains\Notifications\Engine\Contracts\EventRegistry;
use App\Domains\Notifications\Engine\Contracts\MessageComposer;
use App\Domains\Notifications\Engine\Contracts\Sender;
use App\Domains\Notifications\Engine\Data\Delivery;
use App\Domains\Notifications\Engine\Data\SendResult;
use App\Domains\Notifications\Engine\Enums\Channel;
use App\Domains\Notifications\Engine\Enums\Priority;
use App\Domains\Notifications\Models\AppNotification;
use Illuminate\Support\Carbon;

/**
 * The in-app centre (the bell). Writes ONE `app_notifications` row addressed to the recipient — the same table, columns
 * and read model builder L's bell already serves (`kind`, `repeat_count`, `notification_reads`), so the bell, the unread
 * count, «mark all read» and the push hand-off keep working unchanged. Accepted = the row exists.
 */
final class InAppSender implements Sender
{
    public function __construct(private readonly MessageComposer $composer, private readonly EventRegistry $events) {}

    public function channel(): Channel
    {
        return Channel::InApp;
    }

    public function send(Delivery $delivery): SendResult
    {
        if ($delivery->recipient->userId === null) {
            return SendResult::skipped('no_user');
        }

        $en = $this->composer->compose($delivery->withLocale('en'));
        $ar = $this->composer->compose($delivery->withLocale('ar'));
        $object = (array) (($delivery->payload['_objects'] ?? [])[0] ?? []);

        $row = AppNotification::query()->withoutGlobalScopes()->create([
            'tenant_id' => $delivery->tenantId,
            'project_id' => $delivery->projectId,
            'user_id' => $delivery->recipient->userId,
            'type' => $this->events->get($delivery->eventKey)->preferenceType ?? 'message',
            'kind' => mb_substr($delivery->eventKey, 0, 40),
            'severity' => match ($delivery->priority) {
                Priority::Critical => 'critical',
                Priority::Important => 'warning',
                Priority::Info => 'info',
            },
            'title' => $en->title,
            'message' => $en->body,
            'title_ar' => $ar->title,
            'message_ar' => $ar->body,
            'source' => 'notifications_v2',
            'entity_type' => isset($object['type']) ? (string) $object['type'] : null,
            'entity_id' => isset($object['id']) ? (string) $object['id'] : null,
            'action_url' => $en->url,
            'status' => 'unread',
            'dedup_key' => 'nv2:'.$delivery->id,
            'repeat_count' => $delivery->groupCount,
            'last_seen_at' => Carbon::now(),
        ]);

        return SendResult::accepted('app_notification:'.$row->getKey());
    }
}
