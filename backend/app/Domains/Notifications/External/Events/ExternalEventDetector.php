<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Events;

use App\Domains\Funding\Services\ProjectBudgetReader;
use App\Domains\Metrics\Services\DeliveryState;
use App\Domains\Notifications\Engine\Data\DeliveryIntent;
use App\Domains\Notifications\Engine\NotificationEngine;
use App\Domains\Notifications\Engine\Scheduling\DigestPeriods;
use App\Domains\Notifications\External\Digest\DigestDataSource;
use App\Domains\Notifications\External\Recipients\ExternalSettings;
use App\Domains\Projects\Lifecycle\LiveProjects;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The default external events of spec §10 that no other domain raises yet, detected once a day for the projects that
 * have an active external subscription naming them (nothing is computed for a project nobody subscribed to):
 *
 * - `continuity.planning` — the budget lasts until a date within `continuity_days` (default 5), from the last three
 *   complete days' pace (builder H's reader); at most once a week per project;
 * - `performance.improved` — an objective's cost per result improved by `improvement_pct` (default 10%) over the last
 *   7 complete days against the 7 before;
 * - `campaign.launched` — a campaign of the project started yesterday;
 * - `digest.month_end` / `digest.month_start` — on the 1st of the month, in the workspace timezone.
 *
 * `target.reached`, `record.reached` and `budget.renewed` are catalogued but need their domain producers (targets,
 * budgets ledger) — deferred, see Q-notes. Router, checks, review and log apply as to any event.
 */
final class ExternalEventDetector
{
    public function __construct(
        private readonly NotificationEngine $engine,
        private readonly ExternalSettings $settings,
        private readonly DigestDataSource $data,
        private readonly ProjectBudgetReader $budgets,
    ) {}

    /** @return array<string, int> raised per event */
    public function run(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $out = [];
        $rows = DB::table('notification_subscriptions as s')
            ->join('notification_external_recipients as r', 'r.id', '=', 's.recipient_id')
            ->join('notification_subscription_projects as p', 'p.subscription_id', '=', 's.id')
            ->where('s.status', 'active')->where('r.status', 'active')->whereIn('p.project_id', LiveProjects::idsQuery())
            ->whereNotNull('s.event_keys')->get(['s.tenant_id', 'p.project_id', 's.event_keys']);

        $wanted = [];
        foreach ($rows as $row) {
            foreach ((array) json_decode((string) $row->event_keys, true) as $event) {
                $wanted[(string) $row->tenant_id][(string) $row->project_id][(string) $event] = true;
            }
        }

        foreach ($wanted as $tenant => $projects) {
            // DELIVERY-QUIET-001 — nothing is sent to an outside recipient about a project that did not move in 3 days.
            $projects = array_intersect_key($projects, array_flip(app(DeliveryState::class)->watched(array_map('strval', array_keys($projects)), $now->copy()->subDays(DeliveryState::DAYS - 1)->toDateString(), $now->toDateString())));
            if ($projects === []) {
                continue;
            }
            $cfg = $this->settings->for($tenant)['external'];
            $tz = $this->settings->workspace($tenant)->timezone;
            $local = $now->copy()->setTimezone($tz);
            foreach ($projects as $project => $events) {
                $raise = function (string $event, string $object, array $payload = []) use ($tenant, $project, $now, &$out): void {
                    $r = $this->engine->raise(new DeliveryIntent(eventKey: $event, tenantId: $tenant, projectId: $project, objectType: 'external_event', objectId: $object, payload: $payload, occurredAt: $now));
                    $out[$event] = ($out[$event] ?? 0) + (int) $r['queued'];
                };
                try {
                    if (isset($events['continuity.planning'])) {
                        $b = $this->budgets->forProject($project, Carbon::parse($local->toDateString()));
                        $until = $b['run_out_date'] ?? null;
                        $days = (int) ($cfg['continuity_days'] ?? 5);
                        $recent = DB::table('notification_delivery_log')->where('tenant_id', $tenant)->where('project_id', $project)
                            ->where('event_key', 'continuity.planning')->where('created_at', '>=', $now->copy()->subDays(7))->exists();
                        if (($b['has_budget'] ?? false) === true && is_string($until) && ! $recent
                            && Carbon::parse($until)->lte(Carbon::parse($local->toDateString())->addDays($days))) {
                            $raise('continuity.planning', 'continuity:'.$until, ['date' => $until]);
                        }
                    }
                    if (isset($events['performance.improved'])) {
                        $period = DigestPeriods::resolve('last_7', $local->toDateString());
                        if ($period !== null) {
                            $d = $this->data->project($tenant, $project, $period['from'], $period['to'], 'USD');
                            $pct = (float) ($cfg['improvement_pct'] ?? 10) / 100;
                            foreach ((array) ($d['objectives'] ?? []) as $o) {
                                $now7 = $o['cost_per_result'] ?? null;
                                $before = $o['previous_cost_per_result'] ?? null;
                                if (is_numeric($now7) && is_numeric($before) && (float) $before > 0 && ((float) $before - (float) $now7) / (float) $before >= $pct) {
                                    $raise('performance.improved', 'improved:'.$o['objective'].':'.$period['to'], ['period' => $period]);
                                    break;
                                }
                            }
                        }
                    }
                    if (isset($events['campaign.launched'])) {
                        $yesterday = $local->copy()->subDay()->toDateString();
                        foreach (DB::table('unified_campaigns')->where('tenant_id', $tenant)->where('project_id', $project)->whereDate('starts_on', $yesterday)->limit(5)->pluck('id') as $c) {
                            $raise('campaign.launched', 'campaign:'.$c);
                        }
                    }
                    if ($local->day === 1) {
                        foreach (['digest.month_end', 'digest.month_start'] as $event) {
                            if (isset($events[$event])) {
                                $period = $event === 'digest.month_end' ? DigestPeriods::resolve('last_month', $local->toDateString()) : null;
                                $raise($event, $event.':'.$local->format('Y-m'), $period === null ? [] : ['period' => $period]);
                            }
                        }
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        return $out;
    }
}
