<?php

declare(strict_types=1);

namespace App\Domains\Projects\Lifecycle;

use App\Domains\Audit\AuditLogger;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PROJECT-LIFECYCLE-001 §5 (builder N2) — «نقل المشروع لعميل آخر», inside the SAME workspace only.
 *
 * The project keeps its id, so every row keyed by `project_id` alone (metrics, campaigns' children, reports, rules,
 * schedules…) moves with it untouched. Rows that ALSO carry `client_workspace_id` are re-pointed per {@see self::TABLES};
 * `ProjectPurgePlanCoverageTest` fails when a table gains both columns and is not classified here.
 *
 * Never moved: logs (they record what was true then), billing documents (an invoice was issued to the client it names)
 * and the provider connection/credential rows (ad-platform side, rule 6). A target in another workspace — or one that is
 * archived/deleted — is refused. The move is logged in `project_status_transitions` (from = to = the status; reason
 * `moved`; the two client names as snapshots) and the audit log.
 */
final class ProjectMover
{
    public const MOVE = 'move';

    public const KEEP = 'keep';

    /** @var array<string, string> tables with BOTH project_id and client_workspace_id */
    public const TABLES = [
        'action_proposals' => self::MOVE,
        'ai_provider_credentials' => self::MOVE,
        'content_approvals' => self::MOVE,
        'delivery_packs' => self::MOVE,
        'external_campaigns' => self::MOVE,
        'funding_payments' => self::MOVE,
        'launch_plans' => self::MOVE,
        'message_threads' => self::MOVE,
        'platform_evidences' => self::MOVE,
        'project_funding' => self::MOVE,
        'project_integration_bindings' => self::MOVE,
        'tasks' => self::MOVE,
        'unified_campaigns' => self::MOVE,
        // Kept with the client they were true for.
        'invoices' => self::KEEP,
        'quotes' => self::KEEP,
        'app_notifications' => self::KEEP,
        'project_status_transitions' => self::KEEP,
        'integration_credentials' => self::KEEP,
        'provider_connections' => self::KEEP,
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Why this move is refused, or null. Same answer for «not found» and «another workspace» (nothing is disclosed).
     */
    public function refusal(Project $project, string $targetClientId): ?string
    {
        $target = ClientWorkspace::withoutGlobalScopes()->withTrashed()->whereKey($targetClientId)->first();
        if ($target === null || (string) $target->tenant_id !== (string) $project->tenant_id) {
            return 'cross_workspace';
        }
        if ((string) $target->getKey() === (string) $project->client_workspace_id) {
            return 'same_client';
        }
        if ($target->deleted_at !== null || $target->archived_at !== null) {
            return 'target_not_active';
        }
        if (ProjectStatus::normalise($project->status) === ProjectStatus::Deleted) {
            return 'project_deleted';
        }

        return null;
    }

    /**
     * Read-only: what moving would re-point.
     *
     * @return array<string,mixed>
     */
    public function impact(Project $project, ?string $targetClientId): array
    {
        $from = ClientWorkspace::withoutGlobalScopes()->withTrashed()->whereKey($project->client_workspace_id)->first();
        $target = $targetClientId === null ? null : ClientWorkspace::withoutGlobalScopes()->whereKey($targetClientId)->where('tenant_id', $project->tenant_id)->first();

        $moves = [];
        foreach (self::TABLES as $table => $strategy) {
            if ($strategy === self::MOVE) {
                $moves[$table] = $this->scoped($table, $project)->count();
            }
        }

        return [
            'project' => ['id' => (string) $project->getKey(), 'name' => (string) $project->name, 'status' => (string) $project->status],
            'from_client' => $from === null ? null : ['id' => (string) $from->getKey(), 'name' => (string) $from->name],
            'to_client' => $target === null ? null : ['id' => (string) $target->getKey(), 'name' => (string) $target->name],
            'refusal' => $targetClientId === null ? null : $this->refusal($project, $targetClientId),
            'moves' => $moves,
            'kept' => ['invoices' => $this->scoped('invoices', $project)->count(), 'quotes' => $this->scoped('quotes', $project)->count()],
            // People who reach the project through the OLD client (portal users, client team) stop reaching it.
            'from_client_portal_users' => $from === null ? 0 : DB::table('memberships as m')
                ->join('membership_scopes as s', 's.membership_id', '=', 'm.id')
                ->where('m.tenant_id', $project->tenant_id)->where('m.portal', 'portal')->where('m.status', 'active')
                ->where('s.scope_type', 'client_workspace')->where('s.scope_id', (string) $from->getKey())
                ->distinct()->count('m.user_id'),
            'ad_platforms_untouched' => true,
        ];
    }

    /**
     * @return array<string,int> table => rows re-pointed
     *
     * @throws InvalidLifecycleTransition
     */
    public function move(Project $project, string $targetClientId, ?User $actor): array
    {
        if (($why = $this->refusal($project, $targetClientId)) !== null) {
            throw new InvalidLifecycleTransition('Move refused: '.$why.'.');
        }

        return DB::transaction(function () use ($project, $targetClientId, $actor): array {
            $row = Project::withoutGlobalScopes()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            $from = ClientWorkspace::withoutGlobalScopes()->withTrashed()->whereKey($row->client_workspace_id)->first();
            $to = ClientWorkspace::withoutGlobalScopes()->whereKey($targetClientId)->where('tenant_id', $row->tenant_id)->firstOrFail();

            $counts = [];
            foreach (self::TABLES as $table => $strategy) {
                if ($strategy === self::MOVE) {
                    $counts[$table] = $this->scoped($table, $row)->update(['client_workspace_id' => $to->getKey()]);
                }
            }
            DB::table('projects')->where('id', $row->getKey())->where('tenant_id', $row->tenant_id)
                ->update(['client_workspace_id' => $to->getKey(), 'updated_at' => Carbon::now()]);

            $now = Carbon::now();
            $context = ['from_client_id' => $from?->getKey(), 'from_client_name' => $from?->name, 'to_client_id' => (string) $to->getKey(), 'to_client_name' => $to->name, 'rows' => array_filter($counts)];
            $id = (string) Str::uuid();
            DB::table('project_status_transitions')->insert([
                'id' => $id,
                'tenant_id' => $row->tenant_id,
                'project_id' => $row->getKey(),
                'client_workspace_id' => $to->getKey(),
                'name_snapshot' => $row->name,
                'client_name_snapshot' => $to->name,
                'from_status' => (string) $row->status,
                'to_status' => (string) $row->status,
                'actor_id' => $actor?->getKey(),
                'actor_name_snapshot' => $actor?->name,
                'reason' => 'moved',
                'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
                'created_at' => $now->format('Y-m-d H:i:s.uP'),
            ]);
            $this->audit->log(
                action: 'project.moved',
                entityType: Project::class,
                entityId: (string) $row->getKey(),
                before: ['client_workspace_id' => $from?->getKey(), 'client' => $from?->name, 'name' => $row->name],
                after: ['client_workspace_id' => (string) $to->getKey(), 'client' => $to->name, 'name' => $row->name, 'transition_id' => $id, 'rows' => array_filter($counts)],
                reason: 'moved',
                userId: $actor?->getKey() === null ? null : (int) $actor->getKey(),
                tenantId: (string) $row->tenant_id,
            );
            $project->setRawAttributes(Project::withoutGlobalScopes()->whereKey($row->getKey())->firstOrFail()->getAttributes(), true);

            return $counts;
        });
    }

    private function scoped(string $table, Project $project): Builder
    {
        return DB::table($table)->where('tenant_id', $project->tenant_id)->where('project_id', $project->getKey());
    }
}
