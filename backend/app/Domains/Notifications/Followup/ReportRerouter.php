<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Followup;

use App\Domains\Notifications\Engine\Contracts\EntitlementGate;
use App\Domains\Notifications\Engine\Contracts\EventRegistry;
use App\Domains\Notifications\Engine\Data\DeliveryIntent;
use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use App\Domains\Notifications\Engine\Models\ExternalRecipient;
use App\Domains\Notifications\Engine\Models\NotificationSubscription;
use App\Domains\Notifications\Engine\NotificationEngine;
use App\Domains\Notifications\Internal\EngineKinds;
use App\Domains\Notifications\Models\AppNotification;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * NOTIF-V2-FOLLOWUP-001 — «أعد التوجيه بضغطة» (Owner spec §6): the responsible person sends an internal REPORT to an external
 * recipient with one click, through the same pipeline as every external message — the same pre-send checks, the same review,
 * the same immutable log. This class decides only whether the request is allowed and hands the engine an intent; it composes
 * nothing and sends nothing itself (the external message design is the shared `MessageComposer` / `PreSendCheck` contracts).
 *
 * Refused, with a reason the UI shows:
 *  - the plan has no external notifications (entitlement, server-side);
 *  - the item is a SENSITIVE operational notice (sync, link, limit, overrun, automation, campaign without results, plan …):
 *    those are internal only (spec §7) — only a report, or an event that is allowed to be external, can be rerouted;
 *  - the recipient has not confirmed (nothing is sent before consent) or is not subscribed to the item's project (privacy).
 */
final class ReportRerouter
{
    /** Kinds that are reports, not operational notices. */
    private const REPORT_KINDS = ['report_ready'];

    public function __construct(
        private readonly EntitlementGate $gate,
        private readonly EventRegistry $events,
        private readonly NotificationEngine $engine,
    ) {}

    /** @return array{queued: int} */
    public function reroute(User $user, AppNotification $source, string $recipientId, string $tenantId): array
    {
        $this->gate->assertAllows($tenantId, EntitlementKey::External);

        if (! $this->mayReroute((string) $source->kind) || $source->project_id === null) {
            throw ValidationException::withMessages(['notification' => [__('notifications_v2.followup.reroute.sensitive')]]);
        }

        $recipient = ExternalRecipient::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($recipientId)->first();
        if (! $recipient instanceof ExternalRecipient) {
            throw ValidationException::withMessages(['recipient_id' => ['not_found']]);
        }
        if ($recipient->status !== 'active') {
            throw ValidationException::withMessages(['recipient_id' => [__('notifications_v2.followup.reroute.not_active')]]);
        }

        $subscription = NotificationSubscription::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('recipient_id', $recipient->getKey())
            ->where('status', 'active')->get()->first(fn (NotificationSubscription $s): bool => in_array((string) $source->project_id, $s->projectIds(), true));
        if (! $subscription instanceof NotificationSubscription) {
            throw ValidationException::withMessages(['recipient_id' => [__('notifications_v2.followup.reroute.not_subscribed')]]);
        }

        $out = $this->engine->raise(new DeliveryIntent(
            eventKey: 'report.rerouted', tenantId: $tenantId, projectId: (string) $source->project_id,
            objectType: 'rerouted', objectId: $source->getKey().'>'.$recipient->getKey(),
            payload: ['report_title' => (string) $source->title, 'source_notification_id' => (string) $source->getKey(), 'source_project_id' => (string) $source->project_id, 'rerouted_by' => (int) $user->getKey()],
            actorUserId: (int) $user->getKey(), subscriptionId: (string) $subscription->getKey(),
        ));

        return ['queued' => $out['queued']];
    }

    public function mayReroute(string $kind): bool
    {
        if (in_array($kind, self::REPORT_KINDS, true)) {
            return true;
        }
        $event = $this->events->get(EngineKinds::eventFor($kind) ?? '');

        return $event !== null && ! $event->internalOnly && $event->scope->value !== 'internal';
    }
}
