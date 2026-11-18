<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Internal;

use App\Domains\Notifications\Followup\FollowupTasks;
use App\Domains\Notifications\Models\AppNotification;
use Throwable;

/**
 * NOTIF-V2-INTERNAL-001 — the existing producers (budget crossings, alerts, sync and link failures, automations) keep the
 * bell's own writer, because its per-day upsert, its «worse brings it back as unread», its push and its email are builder
 * L's tested behaviour and «no lost alerts» is the rule. What this bridge adds is what the shared engine owns beyond the
 * row: the NAME of the fact as a catalog event, and the follow-up task that fact opens.
 *
 * It reads the finished notification and never alters it. A failure here is reported and swallowed.
 */
final class LegacyBridge
{
    public static function seen(AppNotification $n): void
    {
        try {
            $event = EngineKinds::eventFor($n->kind);
            if ($event === null || $n->project_id === null || $n->tenant_id === null) {
                return;
            }
            if (FollowupTasks::kindForEvent($event) === null) {
                return;
            }

            app(FollowupTasks::class)->openForEvent($event, (string) $n->tenant_id, (string) $n->project_id, [
                'url' => $n->action_url,
            ], 'project', (string) $n->project_id);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
