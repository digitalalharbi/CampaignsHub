<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Services;

use App\Domains\Agents\Models\ActionProposal;
use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * OPERATIONAL-INTELLIGENCE-P1-001 — the exception-first view: a campaign x platform status matrix and the ranked
 * «needs your decision now» list, both built from the alert ledger and the metrics rows the product already has.
 *
 * ## Cell status (deterministic, evidence-based — «healthy» is a claim that needs proof)
 *
 *  1. an OPEN P1/P2 alert on the cell (its platform's connection, or its campaign)  → `problem`
 *  2. no metric row for (campaign, platform) in the last {@see FRESH_DAYS} days        → `no_data`
 *  3. an open P3 alert, or a snoozed one                                             → `watch`
 *  4. otherwise (data is recent AND nothing is open)                                 → `healthy`
 *
 * A campaign-level alert (CPA, ROAS, no results) cannot be attributed to one platform, so it marks every cell of
 * its campaign and the list item says «campaign level». A connection alert names its provider and marks only that column.
 *
 * ## Inventory (the “جرد”)
 *
 * «Active» means `projects.status = 'active'` and `unified_campaigns.status = 'active'`. EVERY active project in the reader's reach is
 * accounted for exactly once: its status is the worst of its cells (problem > no_data > watch > healthy); a project with no active
 * campaign naming a platform has no cell to judge, so it is listed as **unchecked** with its reason — never omitted, never «healthy».
 * `coverage` is «checked of total», where checked means at least one cell was judged.
 *
 * ## Automation
 *
 * A campaign alert shows what the automation did or proposes, from `action_proposals` (propose-only: pending = «awaiting your approval»,
 * executed = «happened automatically»). Nothing here executes, approves or changes anything.
 *
 * ## The ranked list
 *
 * Open events whose decision status is new / in progress, ordered P1 → P2 → P3, then critical before warning, then
 * newest first. Healthy cells are collapsed into a count; nothing healthy is listed.
 */
final class AttentionBoard
{
    public const FRESH_DAYS = 3;

    /** Campaigns classified (every one of them is counted); the matrix SHOWS the most urgent {@see SHOWN_CAP}. */
    private const CAMPAIGN_CAP = 500;

    private const SHOWN_CAP = 60;

    private const LIST_CAP = 15;

    /**
     * @param  list<string>|null  $reach  project ids the reader may see (null = no narrowing)
     * @return array<string,mixed>
     */
    public function build(?array $reach, ?string $project, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        $campaigns = UnifiedCampaign::query()->where('status', 'active')
            ->when($reach !== null, fn ($q) => $q->whereIn('project_id', $reach))
            ->when($project !== null && $project !== '', fn ($q) => $q->where('project_id', $project))
            ->orderBy('name')->orderBy('id')->limit(self::CAMPAIGN_CAP + 1)->get();
        $classifiedAll = $campaigns->count() <= self::CAMPAIGN_CAP;
        $campaigns = $campaigns->take(self::CAMPAIGN_CAP);

        // The same visibility rule every reader of a figure asks: a deselected ad account's rows cannot make a cell look healthy.
        $last = BoundAccountVisibility::apply(DailyMetric::query(), 'daily_metrics')
            ->whereIn('unified_campaign_id', $campaigns->pluck('id')->all())
            ->select('unified_campaign_id', 'provider', DB::raw('max(metric_date) as last_date'))
            ->groupBy('unified_campaign_id', 'provider')->toBase()->get()
            ->mapWithKeys(fn ($r): array => [$r->unified_campaign_id.'|'.$r->provider => (string) $r->last_date]);

        $open = AlertEvent::query()->whereIn('status', ['open', 'snoozed'])
            ->when($reach !== null, fn ($q) => $q->whereIn('project_id', $reach))
            ->when($project !== null && $project !== '', fn ($q) => $q->where(fn ($w) => $w->where('project_id', $project)->orWhereNull('project_id')))
            ->get()
            ->map(fn (AlertEvent $e): AlertEvent => $this->anatomised($e));

        $floor = $now->copy()->subDays(self::FRESH_DAYS)->toDateString();
        $counts = ['problem' => 0, 'no_data' => 0, 'watch' => 0, 'healthy' => 0];
        $rows = [];
        $unplaced = 0;
        $platformsSeen = [];

        foreach ($campaigns as $c) {
            $cells = [];
            foreach (array_values(array_unique(array_filter((array) $c->platforms, 'is_string'))) as $platform) {
                $platformsSeen[$platform] = true;
                $mine = $open->filter(fn (AlertEvent $e): bool => $this->touches($e, $c, $platform));
                $status = match (true) {
                    $mine->contains(fn (AlertEvent $e): bool => $e->status === 'open' && in_array($e->priority, ['P1', 'P2'], true)) => 'problem',
                    ($last[$c->id.'|'.$platform] ?? '') < $floor => 'no_data',
                    $mine->isNotEmpty() => 'watch',
                    default => 'healthy',
                };
                $counts[$status]++;
                $cells[$platform] = ['status' => $status, 'last_data' => $last[$c->id.'|'.$platform] ?? null, 'alert_ids' => $mine->pluck('id')->values()->all()];
            }
            if ($cells === []) {
                // A campaign that names no platform has no cell to judge: it is not «healthy», it is not placed.
                $unplaced++;

                continue;
            }
            $worst = min(array_map(static fn (array $x): int => ['problem' => 0, 'no_data' => 1, 'watch' => 2, 'healthy' => 3][$x['status']], $cells));
            $rows[] = ['worst' => $worst, 'campaign_id' => (string) $c->id, 'project_id' => (string) $c->project_id, 'name' => (string) $c->name, 'cells' => $cells];
        }

        // The matrix shows the most urgent campaigns first, so a cap can only ever hide healthy rows.
        $rowsAll = $rows;
        usort($rows, static fn (array $a, array $b): int => [$a['worst'], $a['name'], $a['campaign_id']] <=> [$b['worst'], $b['name'], $b['campaign_id']]);
        $rowsTotal = count($rows);
        $rows = array_map(static function (array $r): array {
            unset($r['worst']);

            return $r;
        }, array_slice($rows, 0, self::SHOWN_CAP));

        $projects = $this->inventory($reach, $project, $rowsAll);

        $queue = $open->filter(fn (AlertEvent $e): bool => $e->status === 'open' && in_array($e->decision_status ?? 'new', ['new', 'in_progress'], true))
            ->sort(fn (AlertEvent $a, AlertEvent $b): int => [$a->priority, $this->sev($a), -$this->fired($a), $a->id] <=> [$b->priority, $this->sev($b), -$this->fired($b), $b->id])
            ->values();

        $automation = $this->automation($queue->take(self::LIST_CAP));
        $owners = User::query()->whereIn('id', $queue->pluck('owner_id')->filter()->all())->pluck('name', 'id');
        $names = $campaigns->keyBy('id');

        return [
            'platforms' => array_keys($platformsSeen),
            'rows' => $rows,
            'counts' => $counts,
            'cells_total' => array_sum($counts),
            'coverage' => $projects['coverage'],
            'unchecked' => $projects['unchecked'],
            'projects' => $projects['projects'],
            'rows_total' => $rowsTotal,
            'unplaced' => $unplaced,
            'truncated' => $rowsTotal > self::SHOWN_CAP || ! $classifiedAll,
            'needs_decision' => $queue->take(self::LIST_CAP)->map(fn (AlertEvent $e): array => [
                'id' => (string) $e->id, 'priority' => $e->priority, 'severity' => $e->severity, 'type' => $e->type,
                'problem' => $e->anatomy['problem'] ?? null, 'numbers' => $e->anatomy['numbers'] ?? null, 'impact' => $e->anatomy['impact'] ?? null, 'action' => $e->anatomy['action'] ?? null,
                'insufficient' => $e->evidence['sufficient'] !== true,
                'evidence' => array_intersect_key((array) $e->evidence, array_flip(['metric', 'before', 'now', 'threshold', 'threshold_unit'])),
                'owner' => $e->owner_id === null ? null : ['id' => (int) $e->owner_id, 'name' => (string) ($owners[$e->owner_id] ?? '')],
                'decision_status' => $e->decision_status ?? 'new', 'project_id' => $e->project_id,
                'campaign_id' => $e->entity_type === UnifiedCampaign::class ? $e->entity_id : null,
                'campaign_name' => $e->entity_type === UnifiedCampaign::class ? ($names[$e->entity_id]->name ?? null) : null,
                'platforms' => $this->platformsOf($e, $names->get($e->entity_id)),
                'automation' => $automation[(string) $e->id] ?? ['state' => 'needs_decision', 'proposals' => []],
                'level' => $e->entity_type === UnifiedCampaign::class ? 'campaign' : 'connection',
                'path' => $e->entity_type === UnifiedCampaign::class && $e->project_id !== null ? "/app/campaigns/{$e->project_id}/{$e->entity_id}" : '/app/alerts?event='.$e->id,
            ])->all(),
            'needs_decision_total' => $queue->count(),
            'healthy_count' => $counts['healthy'],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows  every classified campaign row (before the display cap)
     * @param  list<string>|null  $reach
     * @return array{coverage: array{total:int,checked:int}, unchecked: list<array<string,string>>, projects: list<array<string,string>>}
     */
    private function inventory(?array $reach, ?string $project, array $rows): array
    {
        $active = Project::query()->where('status', 'active')
            ->when($reach !== null, fn ($q) => $q->whereIn('id', $reach))
            ->when($project !== null && $project !== '', fn ($q) => $q->where('id', $project))
            ->orderBy('name')->orderBy('id')->get(['id', 'name']);

        $rank = ['problem' => 0, 'no_data' => 1, 'watch' => 2, 'healthy' => 3];
        $worst = [];
        foreach ($rows as $r) {
            foreach ($r['cells'] as $cell) {
                $cur = $worst[$r['project_id']] ?? 'healthy';
                $worst[$r['project_id']] = $rank[$cell['status']] < $rank[$cur] ? $cell['status'] : $cur;
            }
        }

        $projects = [];
        $unchecked = [];
        foreach ($active as $p) {
            $id = (string) $p->id;
            if (isset($worst[$id])) {
                $projects[] = ['id' => $id, 'name' => (string) $p->name, 'status' => $worst[$id]];
            } else {
                $unchecked[] = ['id' => $id, 'name' => (string) $p->name, 'reason' => 'no_active_campaign_with_platform'];
                $projects[] = ['id' => $id, 'name' => (string) $p->name, 'status' => 'unchecked'];
            }
        }

        return ['coverage' => ['total' => $active->count(), 'checked' => $active->count() - count($unchecked)], 'unchecked' => $unchecked, 'projects' => $projects];
    }

    /**
     * What the automation did or proposes for each campaign alert — read-only, from the agents' proposals.
     *
     * @param  Collection<int, AlertEvent>  $events
     * @return array<array-key, array{state: string, proposals: list<array<string,string>>}>
     */
    private function automation(Collection $events): array
    {
        $campaignIds = $events->where('entity_type', UnifiedCampaign::class)->pluck('entity_id')->filter()->unique()->all();
        if ($campaignIds === []) {
            return [];
        }
        $externals = ExternalCampaign::query()->whereIn('unified_campaign_id', $campaignIds)->get(['unified_campaign_id', 'external_id']);
        $byExternal = $externals->groupBy('external_id');
        $proposals = ActionProposal::query()->where('target_type', 'campaign')->whereIn('target_external_id', $externals->pluck('external_id')->all())
            ->whereIn('status', [ActionProposal::PENDING, ActionProposal::APPROVED, ActionProposal::EXECUTING, ActionProposal::EXECUTED])
            ->orderByDesc('created_at')->limit(200)->get();

        $perCampaign = [];
        foreach ($proposals as $pr) {
            foreach ($byExternal->get($pr->target_external_id, []) as $ext) {
                $perCampaign[(string) $ext->unified_campaign_id][] = ['action' => (string) $pr->action, 'status' => (string) $pr->status, 'reason' => (string) $pr->reason];
            }
        }

        $out = [];
        foreach ($events as $e) {
            $mine = array_slice($perCampaign[(string) $e->entity_id] ?? [], 0, 3);
            $statuses = array_column($mine, 'status');
            $state = match (true) {
                in_array(ActionProposal::PENDING, $statuses, true) => 'awaiting_approval',
                in_array(ActionProposal::EXECUTED, $statuses, true) => 'done_automatically',
                $mine !== [] => 'in_flight',
                default => 'needs_decision',
            };
            $out[(string) $e->id] = ['state' => $state, 'proposals' => $mine];
        }

        return $out;
    }

    /** An event keeps its stored anatomy; one written before this feature gets it derived on read (never written back). */
    public function anatomised(AlertEvent $e): AlertEvent
    {
        if ($e->priority === null || $e->anatomy === null || $e->evidence === null) {
            $built = AlertAnatomy::build((string) $e->type, (array) $e->context, (string) $e->severity);
            $e->setAttribute('priority', $e->priority ?? $built['priority']);
            $e->setAttribute('evidence', $e->evidence ?? $built['evidence']);
            $e->setAttribute('anatomy', $e->anatomy ?? $built['anatomy']);
        }

        return $e;
    }

    private function touches(AlertEvent $e, UnifiedCampaign $c, string $platform): bool
    {
        if ($e->entity_type === UnifiedCampaign::class) {
            return (string) $e->entity_id === (string) $c->id;
        }
        $provider = (string) (((array) $e->context)['provider'] ?? '');

        return $provider !== '' && $provider === $platform && ($e->project_id === null || (string) $e->project_id === (string) $c->project_id);
    }

    /** @return list<string> */
    private function platformsOf(AlertEvent $e, ?UnifiedCampaign $c): array
    {
        $provider = (string) (((array) $e->context)['provider'] ?? '');

        return $provider !== '' ? [$provider] : array_values(array_filter((array) ($c === null ? [] : $c->platforms), 'is_string'));
    }

    private function fired(AlertEvent $e): int
    {
        $at = $e->getAttribute('last_triggered_at');

        return $at instanceof Carbon ? $at->timestamp : 0;
    }

    private function sev(AlertEvent $e): int
    {
        return ['critical' => 0, 'warning' => 1, 'info' => 2][$e->severity] ?? 3;
    }
}
