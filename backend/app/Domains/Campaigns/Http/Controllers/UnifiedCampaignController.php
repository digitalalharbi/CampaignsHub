<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Resources\ExternalCampaignResource;
use App\Domains\Campaigns\Resources\UnifiedCampaignResource;
use App\Domains\Campaigns\Services\CampaignLinker;
use App\Domains\Projects\Context\ProjectContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Unified (business) campaigns for the active project. Every query is project-scoped by
 * ProjectContext (set by ResolveProject), so cross-project / cross-tenant ids fail-closed with 404.
 */
final class UnifiedCampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('campaigns.view'), 403);

        $query = UnifiedCampaign::query()->withCount('externalCampaigns')->latest();

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($objective = $request->string('objective')->toString()) {
            $query->where('objective', $objective);
        }
        if ($search = $request->string('search')->toString()) {
            $query->where('name', 'ilike', "%{$search}%");
        }

        return ApiResponse::success(UnifiedCampaignResource::collection($query->get()), 'Unified campaigns retrieved.');
    }

    public function show(Request $request, string $project, string $campaign): JsonResponse
    {
        abort_unless($request->user()->hasPermission('campaigns.view'), 403);
        $model = $this->find($campaign)->loadCount('externalCampaigns');

        return ApiResponse::success(new UnifiedCampaignResource($model), 'Unified campaign retrieved.');
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('campaigns.create'), 403);

        $validated = $this->validatePayload($request, creating: true);

        $campaign = UnifiedCampaign::create($validated + [
            'created_by' => $request->user()->id,
            'owner_id' => $validated['owner_id'] ?? $request->user()->id,
            'status' => $validated['status'] ?? CampaignStatus::Draft->value,
        ]);

        $audit->log(action: 'campaign.created', entityType: UnifiedCampaign::class, entityId: (string) $campaign->id, after: ['name' => $campaign->name]);

        return ApiResponse::success(new UnifiedCampaignResource($campaign), 'Unified campaign created.', status: 201);
    }

    public function update(Request $request, string $project, string $campaign, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('campaigns.update'), 403);
        $model = $this->find($campaign);

        $validated = $this->validatePayload($request, creating: false, ignoreId: (string) $model->id);
        $before = $model->only(['name', 'status', 'objective', 'total_budget']);
        $model->update($validated);
        $audit->log(action: 'campaign.updated', entityType: UnifiedCampaign::class, entityId: (string) $model->id, before: $before, after: $model->only(['name', 'status', 'objective', 'total_budget']));

        return ApiResponse::success(new UnifiedCampaignResource($model), 'Unified campaign updated.');
    }

    public function pause(Request $request, string $project, string $campaign, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('campaigns.pause'), 403);

        return $this->transition($campaign, CampaignStatus::Paused, 'campaign.paused', $audit);
    }

    public function activate(Request $request, string $project, string $campaign, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('campaigns.update'), 403);

        return $this->transition($campaign, CampaignStatus::Active, 'campaign.activated', $audit);
    }

    public function destroy(Request $request, string $project, string $campaign, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('campaigns.update'), 403);
        $model = $this->find($campaign);
        $audit->log(action: 'campaign.archived', entityType: UnifiedCampaign::class, entityId: (string) $model->id, before: ['status' => $model->status]);
        $model->delete();

        return ApiResponse::success(null, 'Unified campaign archived.');
    }

    // ---- External-campaign linking -------------------------------------------------------------

    /** External campaigns linked to this unified campaign. */
    public function external(Request $request, string $project, string $campaign): JsonResponse
    {
        abort_unless($request->user()->hasPermission('campaigns.view'), 403);
        $model = $this->find($campaign);

        $externals = ExternalCampaign::query()->where('unified_campaign_id', $model->id)->latest()->get();

        return ApiResponse::success(ExternalCampaignResource::collection($externals), 'Linked external campaigns retrieved.');
    }

    /** Link an external campaign; 409 requires_confirmation if it is already linked elsewhere. */
    public function link(Request $request, string $project, string $campaign, CampaignLinker $linker, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('campaigns.update'), 403);
        $model = $this->find($campaign);

        $validated = $request->validate([
            'external_campaign_id' => ['required', 'uuid'],
            'confirm' => ['sometimes', 'boolean'],
        ]);

        $external = ExternalCampaign::find($validated['external_campaign_id']);
        abort_if($external === null, 404, 'External campaign not found in this project.');

        $result = $linker->link($model, $external, (bool) ($validated['confirm'] ?? false), $request->user()->id);

        if ($result->needsConfirmation) {
            return ApiResponse::error(
                'This external campaign is already linked to another unified campaign. Re-send with confirm=true to move it.',
                meta: ['requires_confirmation' => true, 'current_unified_campaign_id' => $result->previousUnifiedCampaignId],
                status: 409,
            );
        }

        $audit->log(action: 'campaign.external_linked', entityType: ExternalCampaign::class, entityId: (string) $external->id, after: ['unified_campaign_id' => $model->id, 'moved_from' => $result->previousUnifiedCampaignId]);

        return ApiResponse::success(new ExternalCampaignResource($result->external), 'External campaign linked.', status: 201);
    }

    /** Unlink an external campaign (platform state untouched). */
    public function unlink(Request $request, string $project, string $campaign, string $external, CampaignLinker $linker, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('campaigns.update'), 403);
        $model = $this->find($campaign);

        $ec = ExternalCampaign::where('unified_campaign_id', $model->id)->find($external);
        abort_if($ec === null, 404, 'External campaign is not linked to this unified campaign.');

        $linker->unlink($ec);
        $audit->log(action: 'campaign.external_unlinked', entityType: ExternalCampaign::class, entityId: (string) $ec->id, before: ['unified_campaign_id' => $model->id]);

        return ApiResponse::success(null, 'External campaign unlinked.');
    }

    /** Auto-suggest unlinked external campaigns for this unified campaign, ranked by name similarity. */
    public function suggestions(Request $request, string $project, string $campaign, CampaignLinker $linker): JsonResponse
    {
        abort_unless($request->user()->hasPermission('campaigns.view'), 403);
        $model = $this->find($campaign);

        $suggestions = $linker->suggestions($model);

        return ApiResponse::success(ExternalCampaignResource::collection($suggestions), 'Link suggestions retrieved.');
    }

    // ---- helpers -------------------------------------------------------------------------------

    private function transition(string $campaign, CampaignStatus $status, string $action, AuditLogger $audit): JsonResponse
    {
        $model = $this->find($campaign);
        $model->update(['status' => $status->value]);
        $audit->log(action: $action, entityType: UnifiedCampaign::class, entityId: (string) $model->id, after: ['status' => $status->value]);

        return ApiResponse::success(new UnifiedCampaignResource($model), 'Campaign status updated.');
    }

    /** @return array<string, mixed> */
    private function validatePayload(Request $request, bool $creating, ?string $ignoreId = null): array
    {
        $nameRule = Rule::unique('unified_campaigns', 'name')
            ->where('project_id', app(ProjectContext::class)->projectId())
            ->whereNull('deleted_at');
        if ($ignoreId !== null) {
            $nameRule->ignore($ignoreId);
        }

        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:160', $nameRule],
            'client_display_name' => ['nullable', 'string', 'max:160'], // the name a client sees in reports
            'objective' => ['sometimes', Rule::in(CampaignObjective::values())],
            'status' => ['sometimes', Rule::in(CampaignStatus::values())],
            'total_budget' => ['nullable', 'numeric', 'min:0'],
            'budget_currency' => ['sometimes', 'string', 'size:3'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'primary_conversion_purpose' => ['nullable', 'string', 'max:60'],
            'attribution_model' => ['nullable', 'string', 'max:60'],
            'attribution_window' => ['nullable', 'string', 'max:60'],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'target_kpi' => ['nullable', 'array'],
            'audience' => ['nullable', 'string'],
            'regions' => ['nullable', 'array'],
        ]);
    }

    /** Project-scoped lookup (global scopes make cross-project/tenant fail-closed → 404). */
    private function find(string $id): UnifiedCampaign
    {
        $model = UnifiedCampaign::find($id);
        abort_if($model === null, 404, 'Unified campaign not found.');

        return $model;
    }
}
