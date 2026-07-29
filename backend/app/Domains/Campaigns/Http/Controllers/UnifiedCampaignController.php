<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\CampaignPerformanceLabel;
use App\Domains\Campaigns\Enums\CampaignPriority;
use App\Domains\Campaigns\Enums\CampaignStage;
use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Resources\ExternalCampaignResource;
use App\Domains\Campaigns\Resources\UnifiedCampaignResource;
use App\Domains\Campaigns\Services\CampaignLinker;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Taxonomy\Services\TaxonomyService;
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
        foreach (['stage', 'performance_label', 'priority'] as $classField) {
            if ($value = $request->string($classField)->toString()) {
                $query->where($classField, $value);
            }
        }
        if ($request->boolean('needs_attention')) {
            $query->whereIn('performance_label', CampaignPerformanceLabel::needsAttention());
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
        $before = $model->only(['name', 'status', 'objective', 'total_budget', 'stage', 'performance_label', 'priority']);
        $model->update($validated);
        $audit->log(action: 'campaign.updated', entityType: UnifiedCampaign::class, entityId: (string) $model->id, before: $before, after: $model->only(['name', 'status', 'objective', 'total_budget', 'stage', 'performance_label', 'priority']));

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
            'stage' => ['nullable', Rule::in(CampaignStage::values())],
            'performance_label' => ['nullable', Rule::in(CampaignPerformanceLabel::values())],
            'priority' => ['sometimes', Rule::in(CampaignPriority::values())],
            'total_budget' => ['nullable', 'numeric', 'min:0'],
            'budget_currency' => ['sometimes', 'string', 'size:3'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'primary_conversion_purpose' => ['nullable', 'string', 'max:60'],
            'attribution_model' => ['nullable', 'string', 'max:60'],
            'attribution_window' => ['nullable', 'string', 'max:60'],
            // The owner MUST belong to this tenant — never assign a user from another workspace.
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'target_kpi' => ['nullable', 'array'],
            'audience' => ['nullable', 'string'],
            // Additive taxonomy-driven multi-selects. Keys with a canonical option set are validated against the
            // engine; tenant-managed vocabularies (regions/audiences/conversion_events/tags) accept free strings.
            'regions' => ['sometimes', 'nullable', 'array'],
            'regions.*' => ['string', 'max:64'],
            'platforms' => ['sometimes', 'nullable', 'array'],
            'platforms.*' => [$this->taxonomyOptionRule('campaign.platforms')],
            'audiences' => ['sometimes', 'nullable', 'array'],
            'audiences.*' => ['string', 'max:120'],
            'conversion_events' => ['sometimes', 'nullable', 'array'],
            'conversion_events.*' => ['string', 'max:120'],
            'creative_types' => ['sometimes', 'nullable', 'array'],
            'creative_types.*' => [$this->taxonomyOptionRule('campaign.creative_types')],
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
        ]);
    }

    /**
     * A per-element rule that validates a submitted value against a taxonomy definition's active option keys
     * (platform ∪ tenant). Runs only for values that are actually present, so campaigns that never send the
     * field pay no taxonomy query. An unknown/unseeded definition yields no keys → the value is left to the
     * array rule (fail-open on infrastructure, not on a real unknown key).
     */
    private function taxonomyOptionRule(string $definitionKey): callable
    {
        return function (string $attribute, mixed $value, callable $fail) use ($definitionKey): void {
            $allowed = app(TaxonomyService::class)->activeOptionKeys($definitionKey);
            if ($allowed !== [] && ! in_array($value, $allowed, true)) {
                $fail("The selected {$attribute} is not a valid {$definitionKey} option.");
            }
        };
    }

    /** Project-scoped lookup (global scopes make cross-project/tenant fail-closed → 404). */
    private function find(string $id): UnifiedCampaign
    {
        $model = UnifiedCampaign::find($id);
        abort_if($model === null, 404, 'Unified campaign not found.');

        return $model;
    }
}
