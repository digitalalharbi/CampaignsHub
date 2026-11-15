<?php

declare(strict_types=1);

namespace App\Domains\Projects\Lifecycle;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PROJECT-LIFECYCLE-001 §PROTECTION — a READ-ONLY, counts-only picture of one project, for before/after comparison.
 *
 * Every read is filtered by the project id (and its client for portal users). Nothing here writes. The output carries no
 * generation time and is key-sorted, so the same data always prints the same JSON and a change elsewhere never moves it.
 * Holds no names of people and no figures beyond totals.
 */
final class ProjectSnapshot
{
    /** @return array<string,mixed>|null null when no such project exists */
    public function for(string $projectId, ?Carbon $asOf = null): ?array
    {
        $project = DB::table('projects')->where('id', $projectId)->first(['id', 'tenant_id', 'client_workspace_id', 'status', 'deleted_at']);
        if ($project === null) {
            return null;
        }
        $asOf ??= Carbon::today();
        $from = $asOf->copy()->subDays(30)->toDateString();
        $to = $asOf->copy()->subDay()->toDateString();
        $p = (string) $project->id;
        $client = (string) $project->client_workspace_id;

        $lastSync = DB::table('metric_sync_runs')
            ->where('project_id', $p)->where('status', 'success')
            ->groupBy('provider')->selectRaw('provider, max(finished_at) as last')->orderBy('provider')
            ->pluck('last', 'provider')
            ->map(static fn ($v): ?string => $v === null ? null : Carbon::parse((string) $v)->toIso8601String())
            ->all();

        $metrics = DB::table('daily_metrics')
            ->where('project_id', $p)->whereBetween('metric_date', [$from, $to])
            ->groupBy('metric_key')->selectRaw('metric_key, round(sum(value)::numeric, 4) as total')->orderBy('metric_key')
            ->pluck('total', 'metric_key')
            ->map(static fn ($v): string => number_format((float) $v, 4, '.', ''))
            ->all();

        $portalUsers = DB::table('client_workspace_user')->where('client_workspace_id', $client)->count();
        $portalMembers = DB::table('memberships as m')
            ->join('membership_scopes as s', 's.membership_id', '=', 'm.id')
            ->where('m.portal', 'portal')->where('s.scope_type', 'client')->where('s.scope_id', $client)
            ->distinct()->count('m.id');

        $snapshot = [
            'project' => ['id' => $p, 'status' => (string) $project->status, 'soft_deleted' => $project->deleted_at !== null],
            'accounts' => [
                'bindings_total' => DB::table('project_integration_bindings')->where('project_id', $p)->count(),
                'bindings_active' => DB::table('project_integration_bindings')->where('project_id', $p)->where('is_active', true)->count(),
                'bindings_syncing' => DB::table('project_integration_bindings')->where('project_id', $p)->where('is_active', true)->where('sync_enabled', true)->count(),
            ],
            'campaigns' => DB::table('unified_campaigns')->where('project_id', $p)->count(),
            'last_successful_sync_per_platform' => $lastSync,
            'metric_totals_30d' => ['from' => $from, 'to' => $to, 'totals' => $metrics],
            'scheduled_reports' => [
                'total' => DB::table('report_schedules')->where('project_id', $p)->count(),
                'active' => DB::table('report_schedules')->where('project_id', $p)->where('active', true)->count(),
            ],
            'client_portal_users' => ['client_workspace_users' => $portalUsers, 'portal_memberships' => $portalMembers],
            'budgets' => DB::table('project_funding')->where('project_id', $p)->count(),
            'spend_limits' => [
                'total' => DB::table('spend_limits')->where('project_id', $p)->count(),
                'active' => DB::table('spend_limits')->where('project_id', $p)->where('active', true)->count(),
            ],
            'rules' => [
                'alert_rules_active' => DB::table('alert_rules')->where('project_id', $p)->where('active', true)->count(),
                'automation_rules_enabled' => DB::table('automation_rules')->where('project_id', $p)->where('enabled', true)->count(),
                'agent_routines_enabled' => DB::table('agent_routines')->where('project_id', $p)->where('enabled', true)->count(),
            ],
            'subscriptions' => [
                'notification_recipients' => DB::table('notification_recipients')->where('project_id', $p)->count(),
                'external_digest_recipients' => DB::table('external_digest_recipient_projects')->where('project_id', $p)->count(),
            ],
            'open' => [
                'alerts' => DB::table('alert_events')->where('project_id', $p)->whereIn('status', ['open', 'snoozed'])->count(),
                'tasks' => DB::table('tasks')->where('project_id', $p)->whereNotIn('status', ['completed', 'cancelled'])->count(),
            ],
        ];

        return self::sortKeys($snapshot);
    }

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     * @return list<string> dotted paths that differ
     */
    public static function diff(array $a, array $b, string $prefix = ''): array
    {
        $out = [];
        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $key) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $x = $a[$key] ?? null;
            $y = $b[$key] ?? null;
            if (is_array($x) && is_array($y)) {
                $out = [...$out, ...self::diff($x, $y, $path)];
            } elseif ($x !== $y) {
                $out[] = $path;
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function sortKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(static fn ($v) => is_array($v) ? self::sortKeys($v) : $v, $value);
    }
}
