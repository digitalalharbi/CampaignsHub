<?php

declare(strict_types=1);

namespace App\Domains\Requests\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestConversion;
use App\Domains\Tasks\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Converts an external request into a client → project → draft campaign + setup tasks, exactly once.
 * Transactional and idempotent: a completed conversion is returned as-is on any retry (no duplicate
 * client/project/campaign/tasks); any mid-flight failure rolls back fully and is audited, and the
 * request stays convertible. External side effects (email/websocket) are NOT done inside the tx.
 */
final class RequestConversionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{client_id?: string|null, idempotency_key?: string|null}  $input
     */
    public function convert(ExternalRequest $request, array $input, User $actor): RequestConversion
    {
        // Fast path: already converted → return the existing result (no new entities).
        $done = RequestConversion::where('tenant_id', $request->tenant_id)
            ->where('external_request_id', $request->id)->where('status', 'completed')->first();
        if ($done !== null) {
            return $done;
        }

        try {
            return DB::transaction(function () use ($request, $input, $actor) {
                // Serialize concurrent conversions of the same request.
                $locked = ExternalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

                $already = RequestConversion::where('tenant_id', $locked->tenant_id)
                    ->where('external_request_id', $locked->id)->where('status', 'completed')->first();
                if ($already !== null) {
                    return $already;
                }

                $client = $this->resolveClient($locked, $input);
                $project = $this->createProject($locked, $client, $actor);
                $campaign = $this->createDraftCampaign($locked, $client, $project, $actor);
                $this->createSetupTasks($locked, $client, $project, $actor);

                $conversion = RequestConversion::create([
                    'tenant_id' => $locked->tenant_id,
                    'external_request_id' => $locked->id,
                    'client_id' => $client->id,
                    'project_id' => $project->id,
                    'campaign_id' => $campaign->id,
                    'idempotency_key' => $input['idempotency_key'] ?? (string) Str::uuid(),
                    'status' => 'completed',
                    'started_by' => $actor->id,
                    'completed_by' => $actor->id,
                    'started_at' => now(),
                    'completed_at' => now(),
                ]);

                $locked->forceFill(['client_id' => $client->id, 'project_id' => $project->id, 'campaign_id' => $campaign->id, 'last_activity_at' => now()])->save();
                $locked->events()->create(['type' => 'converted', 'actor_id' => $actor->id, 'is_client_visible' => false, 'message' => 'Converted to client/project/campaign', 'created_at' => now()]);
                $this->audit->log('request.converted', 'external_request', $locked->id, null, ['client_id' => $client->id, 'project_id' => $project->id, 'campaign_id' => $campaign->id]);

                return $conversion;
            });
        } catch (Throwable $e) {
            // Everything above rolled back — no partial entities. Audit the failure; the request stays convertible.
            $this->audit->log('request.conversion_failed', 'external_request', $request->id, null, [
                'code' => class_basename($e), 'message' => Str::limit($e->getMessage(), 300),
            ]);
            throw $e;
        }
    }

    /** @param array{client_id?: string|null} $input */
    private function resolveClient(ExternalRequest $request, array $input): ClientWorkspace
    {
        if (! empty($input['client_id'])) {
            $client = ClientWorkspace::where('tenant_id', $request->tenant_id)->find($input['client_id']);
            if ($client === null) {
                throw new RuntimeException('Selected client is not in this workspace.'); // never merge cross-tenant
            }

            return $client;
        }

        // Create a new client from the request — no re-entry of data.
        return ClientWorkspace::create([
            'tenant_id' => $request->tenant_id,
            'name' => $request->company_name ?: $request->contact_name ?: 'New client',
            'slug' => Str::slug(($request->company_name ?: $request->contact_name ?: 'client').'-'.Str::lower(Str::random(5))),
            'mode' => 'managed',
            'status' => 'active',
            'client_status' => 'onboarding',
            'industry' => is_array($request->metadata) ? ($request->metadata['industry'] ?? null) : null,
            'client_source' => 'request_portal',
            'owner_id' => $request->assigned_to,
            'source_request_id' => $request->id,
        ]);
    }

    private function createProject(ExternalRequest $request, ClientWorkspace $client, User $actor): Project
    {
        return Project::create([
            'tenant_id' => $request->tenant_id,
            'client_workspace_id' => $client->id,
            'account_manager_id' => $request->assigned_to ?? $actor->id,
            'name' => Str::limit($request->objective ?: $request->type->name_en, 80, ''),
            'status' => 'setup',
            'meta' => ['source_request_id' => $request->id, 'module' => $request->module],
        ]);
    }

    private function createDraftCampaign(ExternalRequest $request, ClientWorkspace $client, Project $project, User $actor): UnifiedCampaign
    {
        $meta = is_array($request->metadata) ? $request->metadata : [];
        $platforms = is_array($meta['platforms'] ?? null) ? $meta['platforms'] : [];

        return UnifiedCampaign::create([
            'tenant_id' => $request->tenant_id,
            'project_id' => $project->id,
            'client_workspace_id' => $client->id,
            'name' => Str::limit($request->objective ?: ($request->type->name_en.' campaign'), 80, ''),
            'objective' => $this->mapObjective($request),
            'status' => 'draft', // honest — Setup Required; never Active/Connected/Live before a real sync
            'stage' => 'setup',
            'priority' => $request->priority,
            'total_budget' => $request->budget,
            'budget_currency' => $request->currency,
            'starts_on' => $request->start_date,
            'ends_on' => $request->due_date,
            'audience' => $meta['audience'] ?? null,
            'regions' => isset($meta['regions']) ? [$meta['regions']] : null,
            'created_by' => $actor->id,
            'meta' => ['source_request_id' => $request->id, 'platforms' => $platforms, 'setup_required' => true],
        ]);
    }

    /** Map the request onto a supported campaign objective. */
    private function mapObjective(ExternalRequest $request): string
    {
        $meta = is_array($request->metadata) ? $request->metadata : [];
        $explicit = strtolower((string) ($meta['campaign_objective'] ?? ''));
        $known = ['sales', 'leads', 'awareness', 'traffic', 'engagement', 'app_installs', 'video_views', 'store_visits', 'custom'];
        if (in_array($explicit, $known, true)) {
            return $explicit;
        }

        return match ($request->module) {
            'paid_media' => 'sales',
            'influencer_marketing' => 'awareness',
            default => 'custom',
        };
    }

    private function createSetupTasks(ExternalRequest $request, ClientWorkspace $client, Project $project, User $actor): void
    {
        foreach ($this->setupTaskTitles($request->module) as $title) {
            Task::create([
                'tenant_id' => $request->tenant_id,
                'client_workspace_id' => $client->id,
                'project_id' => $project->id,
                'assignee_id' => $request->assigned_to,
                'created_by' => $actor->id,
                'title' => $title,
                'status' => 'todo', // canonical Task status (was legacy 'open')
                'priority' => 'normal', // canonical Task priority (was legacy 'medium')
                'meta' => ['source_request_id' => $request->id],
            ]);
        }
    }

    /** @return list<string> service-specific setup tasks (not one static list for all). */
    private function setupTaskTitles(string $module): array
    {
        return match ($module) {
            'paid_media' => [
                'Collect Advertising Account Access', 'Verify Tracking and Conversion Events', 'Connect Advertising Platforms',
                'Review Creative Assets', 'Confirm Budget and Dates', 'Build Media Plan', 'Configure Reporting', 'Launch Readiness Review',
            ],
            'tracking' => [
                'Audit Current Tracking', 'Configure GA4 / GTM', 'Define Conversion Events', 'Verify Pixels and CAPI',
                'Test Event Quality', 'Document Tracking Setup',
            ],
            'analytics', 'reporting' => [
                'Confirm Data Sources', 'Define Source of Truth', 'Validate Currency and Timezone', 'Configure Attribution',
                'Create Report Template', 'Verify Export and Sharing',
            ],
            'influencer_marketing' => [
                'Confirm Campaign Brief', 'Define Creator Criteria', 'Review Deliverables', 'Set Approval Workflow', 'Prepare Timeline',
            ],
            default => ['Review Request Details', 'Confirm Scope and Deliverables', 'Plan Next Steps'],
        };
    }
}
