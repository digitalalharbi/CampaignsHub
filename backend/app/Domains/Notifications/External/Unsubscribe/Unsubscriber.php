<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Unsubscribe;

use App\Domains\Notifications\Engine\Contracts\SubscriptionLog;
use App\Domains\Notifications\Engine\Data\DeliveryIntent;
use App\Domains\Notifications\Engine\Enums\SubscriptionLogAction;
use App\Domains\Notifications\Engine\NotificationEngine;
use App\Domains\Notifications\Engine\Scheduling\DigestPeriods;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What one click on «إلغاء الاشتراك» does (spec §9): this type / this project / all / reduce frequency.
 *
 * Every action leaves the rest of the recipient's subscriptions untouched, is written to the immutable subscription log,
 * and raises the internal notice («[الاسم] ألغى استلام تقارير [المشروع] بتاريخ …») to the workspace's responsible people.
 * Rows are never deleted. A copy address (CC/BCC without a recipient row) is taken off that subscription's copy list.
 */
final class Unsubscriber
{
    public const ACTIONS = ['type', 'project', 'all', 'reduce'];

    public function __construct(private readonly SubscriptionLog $log, private readonly NotificationEngine $engine) {}

    /** @param array<string, string> $claims verified token claims @return array{done: bool, action: string} */
    public function apply(array $claims, string $action, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $tenant = $claims['t'];
        if (isset($claims['c'])) {
            return ['done' => $this->dropCopy($tenant, $claims, $now), 'action' => 'copy'];
        }

        $recipient = DB::table('notification_external_recipients')->where('tenant_id', $tenant)->where('id', $claims['r'])->first();
        if ($recipient === null || $recipient->status === 'cancelled') {
            return ['done' => false, 'action' => $action];
        }
        $subs = DB::table('notification_subscriptions')->where('tenant_id', $tenant)->where('recipient_id', $recipient->id)->where('status', '!=', 'cancelled');
        $project = $claims['p'] ?? null;

        $done = match ($action) {
            'type' => $this->dropType($tenant, (clone $subs)->where('id', $claims['s'] ?? '')->first(), $claims['e'] ?? 'digest.periodic', $now),
            'project' => $project !== null && $this->dropProject($tenant, (clone $subs)->get(), $project, $now),
            'all' => $this->dropAll($tenant, (string) $recipient->id, $now),
            'reduce' => $this->reduce($tenant, (clone $subs)->where('id', $claims['s'] ?? '')->first(), $now),
            default => false,
        };
        if (! $done) {
            return ['done' => false, 'action' => $action];
        }

        $reduced = $action === 'reduce';
        $this->log->record($tenant, $reduced ? SubscriptionLogAction::Reduced : SubscriptionLogAction::Unsubscribed, (string) $recipient->id, $claims['s'] ?? null, ['scope' => $action, 'project_id' => $project, 'event' => $claims['e'] ?? null]);
        $notice = $project ?? DB::table('notification_subscription_projects')->where('subscription_id', $claims['s'] ?? '')->value('project_id');
        if ($notice !== null) {
            $this->engine->raise(new DeliveryIntent(
                eventKey: $reduced ? 'external.recipient_reduced' : 'external.recipient_unsubscribed',
                tenantId: $tenant, projectId: (string) $notice, objectType: 'external_recipient', objectId: (string) $recipient->id, threshold: $action,
                payload: ['recipient' => (string) ($recipient->name ?: $recipient->email), 'date' => $now->toDateString()], occurredAt: $now,
            ));
        }

        return ['done' => true, 'action' => $action];
    }

    private function dropType(string $tenant, ?object $sub, string $event, Carbon $now): bool
    {
        if ($sub === null) {
            return false;
        }
        $json = static fn ($v): array => $v === null ? [] : array_values((array) (is_string($v) ? json_decode($v, true) : $v));
        $digest = $json($sub->digest_types);
        $events = $json($sub->event_keys);
        if (str_starts_with($event, 'digest.periodic')) {
            $digest = [];
        } else {
            $events = array_values(array_diff($events, [$event]));
        }
        $status = $digest === [] && $events === [] ? 'cancelled' : (string) $sub->status;
        DB::table('notification_subscriptions')->where('id', $sub->id)->where('tenant_id', $tenant)->update([
            'digest_types' => json_encode($digest), 'event_keys' => json_encode($events), 'status' => $status,
            'frequency' => $digest === [] ? null : $sub->frequency, 'period_key' => $digest === [] ? null : $sub->period_key, 'updated_at' => $now,
        ]);

        return true;
    }

    /** @param iterable<object> $subs */
    private function dropProject(string $tenant, iterable $subs, string $project, Carbon $now): bool
    {
        $any = false;
        foreach ($subs as $sub) {
            $removed = DB::table('notification_subscription_projects')->where('subscription_id', $sub->id)->where('tenant_id', $tenant)->where('project_id', $project)->delete();
            if ($removed > 0) {
                $any = true;
                if (DB::table('notification_subscription_projects')->where('subscription_id', $sub->id)->doesntExist()) {
                    DB::table('notification_subscriptions')->where('id', $sub->id)->update(['status' => 'cancelled', 'updated_at' => $now]);
                }
            }
        }

        return $any;
    }

    private function dropAll(string $tenant, string $recipient, Carbon $now): bool
    {
        DB::table('notification_subscriptions')->where('tenant_id', $tenant)->where('recipient_id', $recipient)->update(['status' => 'cancelled', 'updated_at' => $now]);
        DB::table('notification_external_recipients')->where('tenant_id', $tenant)->where('id', $recipient)->update(['status' => 'cancelled', 'updated_at' => $now]);

        return true;
    }

    /** daily → weekly → monthly, with the period moved to the one that fits; events-only subscriptions have nothing to reduce. */
    private function reduce(string $tenant, ?object $sub, Carbon $now): bool
    {
        if ($sub === null || $sub->frequency === null || $sub->frequency === 'monthly') {
            return false;
        }
        $to = $sub->frequency === 'daily' ? 'weekly' : 'monthly';
        $check = DigestPeriods::check((string) $sub->period_key, $to);
        DB::table('notification_subscriptions')->where('id', $sub->id)->where('tenant_id', $tenant)->update([
            'frequency' => $to, 'period_key' => $check['ok'] ? $sub->period_key : ($check['alternative'] ?? 'last_month'), 'next_run_at' => null, 'updated_at' => $now,
        ]);

        return true;
    }

    /** @param array<string, string> $claims */
    private function dropCopy(string $tenant, array $claims, Carbon $now): bool
    {
        $sub = DB::table('notification_subscriptions')->where('tenant_id', $tenant)->where('id', $claims['s'] ?? '')->first();
        if ($sub === null) {
            return false;
        }
        $address = strtolower($claims['c']);
        $json = static fn ($v): array => $v === null ? [] : array_values((array) (is_string($v) ? json_decode($v, true) : $v));
        $cc = array_values(array_diff($json($sub->cc), [$address]));
        $bcc = array_values(array_diff($json($sub->bcc), [$address]));
        DB::table('notification_subscriptions')->where('id', $sub->id)->update(['cc' => json_encode($cc), 'bcc' => json_encode($bcc), 'updated_at' => $now]);
        $this->log->record($tenant, SubscriptionLogAction::Unsubscribed, (string) $sub->recipient_id, (string) $sub->id, ['scope' => 'copy', 'address_hash' => hash('sha256', $address)]);

        return true;
    }
}
