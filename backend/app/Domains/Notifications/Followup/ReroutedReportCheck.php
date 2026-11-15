<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Followup;

use App\Domains\Notifications\Engine\Contracts\PreSendCheck;
use App\Domains\Notifications\Engine\Data\Delivery;
use App\Domains\Notifications\Engine\Enums\DeliveryStatus;
use Illuminate\Support\Facades\DB;

/**
 * NOTIF-V2-FOLLOWUP-001 — the pre-send check of a rerouted report (container tag `notifications.presend`, the contract builder Q's
 * checks also implement). Last line of the privacy rule: the report's project must still be one the recipient's subscription names,
 * and the source notification must be the same project's — otherwise the message is HELD FOR PRIVACY, never sent.
 */
final class ReroutedReportCheck implements PreSendCheck
{
    public function check(Delivery $delivery): ?array
    {
        if ($delivery->eventKey !== 'report.rerouted') {
            return null;
        }
        $source = (string) ($delivery->payload['source_project_id'] ?? '');
        if ($delivery->projectId === null || $source !== $delivery->projectId) {
            return [DeliveryStatus::HeldForPrivacy, 'rerouted_project_mismatch'];
        }
        $covered = $delivery->subscriptionId !== null && DB::table('notification_subscription_projects')
            ->where('tenant_id', $delivery->tenantId)->where('subscription_id', $delivery->subscriptionId)->where('project_id', $delivery->projectId)->exists();

        return $covered ? null : [DeliveryStatus::HeldForPrivacy, 'project_outside_subscription'];
    }
}
