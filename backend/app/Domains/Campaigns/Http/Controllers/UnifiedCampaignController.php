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
use App\Domains\Tenancy\Context\TenantContext;
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
    /**
     * How many campaigns the workspace list returns before it says «there are more».
     *
     * Matches the report scope builder's ceiling, because they are the same question asked by two
     * screens and a reader who learns the number on one should not meet a different one on the other.
     */
    private const LIST_LIMIT = 500;

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('campaigns.view'), 403);

        $query = UnifiedCampaign::query()->withCount('externalCampaigns')->latest();

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        /*
         * ANALYTICS-OBJECTIVE-SYSTEM-001 — a LIST of raw objectives, the same contract the metrics
         * API has.
         *
         * A single value matched with `=` could not express a canonical bucket at all: «الوعي
         * والتفاعل» covers awareness, reach, video views and engagement, so the one screen where a
         * reader manages campaigns was the one screen that could not be asked the product's own
         * question. A single value still works — a list of one is a list — and a blank value is no
         * filter rather than an objective named empty string, which a cleared control would
         * otherwise use to empty the page.
         */
        $objectives = array_values(array_filter(
            array_map('trim', explode(',', $request->string('objective')->toString())),
            static fn (string $o): bool => $o !== '',
        ));
        if ($objectives !== []) {
            $query->whereIn('objective', $objectives);
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

        /*
         * CAMPAIGN-INTELLIGENCE-HUB — the workspace list is BOUNDED, and says when it stopped.
         *
         * This ended `->get()`: every campaign in the project, on the one screen an operator opens
         * first, on the account with the most of them. The weight is the smaller half of the problem.
         * The larger half is that a list which returns everything and a list which stops silently
         * look identical to the reader — so the day somebody adds a limit for performance, the screen
         * starts lying without anything changing on it.
         *
         * One row past the cap is fetched so «there are more» is measured rather than inferred: at
         * exactly 500 campaigns an inference would report more that are not there, and an operator
         * told their list is incomplete goes looking for something that was never missing.
         *
         * The filters above narrow this server-side already — status, objective, search — so the cap
         * is the ceiling on an unfiltered browse rather than the answer to «show me my campaign».
         */
        $rows = $query->limit(self::LIST_LIMIT + 1)->get();
        $truncated = $rows->count() > self::LIST_LIMIT;

        return ApiResponse::success(
            UnifiedCampaignResource::collection($rows->take(self::LIST_LIMIT)),
            'Unified campaigns retrieved.',
            meta: ['truncated' => $truncated, 'limit' => self::LIST_LIMIT],
        );
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

        /*
         * An objective change is recorded as a REVIEW, separately from the rest of the edit
         * (REPORT-OBJECTIVE-002).
         *
         * This one field decides whether a campaign's spend lands in a client's cost-per-order, so
         * `sales` in the column is not enough on its own — the report has to be able to say whether
         * that was the platform's word, a person's correction, or an import default nobody has
         * looked at. `objective_source` is set here rather than accepted from the request precisely
         * so a caller cannot claim its classification came from the platform.
         *
         * It also gets its own audit action. Folded into `campaign.updated` it would be findable
         * only by reading every campaign edit ever made and diffing the payloads.
         */
        if (array_key_exists('objective', $validated) && $validated['objective'] !== $before['objective']) {
            $model->forceFill([
                'objective_source' => 'manual',
                'objective_corrected_by' => $request->user()->id,
                'objective_corrected_at' => now(),
            ])->save();

            $audit->log(
                action: 'campaign.objective.corrected',
                entityType: UnifiedCampaign::class,
                entityId: (string) $model->id,
                before: ['objective' => $before['objective']],
                after: ['objective' => $model->objective, 'objective_source' => 'manual'],
            );
        }

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
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', app(TenantContext::class)->tenantId())],
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
