<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Log;

use App\Domains\Notifications\Engine\Contracts\DeliveryLog;
use App\Domains\Notifications\Engine\Data\Delivery;
use App\Domains\Notifications\Engine\Data\SendResult;
use App\Domains\Notifications\Engine\Enums\DeliveryStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Appends to `notification_delivery_log` (spec §4). There is no update or delete method, here or anywhere: the table's
 * trigger refuses both. Project and workspace names are snapshotted so the proof survives renames and deletions.
 */
final class ImmutableDeliveryLog implements DeliveryLog
{
    public function record(Delivery $delivery, DeliveryStatus $status, ?string $reason = null, ?SendResult $result = null): string
    {
        $id = (string) Str::uuid();
        $accepted = $status === DeliveryStatus::Sent && $result !== null && $result->accepted;

        DB::table('notification_delivery_log')->insert([
            'id' => $id,
            'tenant_id' => $delivery->tenantId,
            'delivery_id' => $delivery->id,
            'reference' => $delivery->reference,
            'event_key' => $delivery->eventKey,
            'scope' => $delivery->scope->value,
            'channel' => $delivery->channel->value,
            'recipient_kind' => $delivery->recipient->kind->value,
            'recipient_user_id' => $delivery->recipient->userId,
            'recipient_external_id' => $delivery->recipient->externalId,
            'recipient_address' => $delivery->recipient->address,
            'project_id' => $delivery->projectId,
            'project_name' => isset($delivery->payload['project_name']) ? mb_substr((string) $delivery->payload['project_name'], 0, 200) : null,
            'workspace_name' => isset($delivery->payload['workspace_name']) ? mb_substr((string) $delivery->payload['workspace_name'], 0, 200) : null,
            'status' => $status->value,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 120),
            'content_version' => $delivery->contentVersion,
            'attempt' => $delivery->attempts,
            'provider_message_id' => $accepted ? $result->providerMessageId : null,
            'provider_accepted_at' => $accepted ? $result->acceptedAt : null,
            'created_at' => Carbon::now(),
        ]);

        return $id;
    }
}
