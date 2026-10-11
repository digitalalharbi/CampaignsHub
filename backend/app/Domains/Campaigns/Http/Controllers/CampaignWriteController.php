<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Http\Controllers;

use App\Domains\Campaigns\Management\Write\CampaignWriteService;
use App\Domains\Campaigns\Management\Write\WriteAction;
use App\Domains\Campaigns\Management\Write\WriteAdapterRegistry;
use App\Domains\Campaigns\Management\Write\WriteLevel;
use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Projects\Context\ProjectContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CAMPAIGN-MGMT-WRITE-001 — the HTTP face of `CampaignWriteService`.
 *
 * Input is validated against the PROVIDER's vocabulary for the entity it addresses (its bid
 * strategies, its budget kinds), so a value the platform would reject is refused here with a reason
 * rather than spent on a round-trip. A destructive action needs `confirm: true` in the body — the
 * interface asks, and the server does not take the interface's word that it did.
 */
final class CampaignWriteController extends Controller
{
    private const CAP_STRATEGIES = ['LOWEST_COST_WITH_BID_CAP', 'COST_CAP', 'LOWEST_COST_WITH_MAX_BID', 'TARGET_COST', 'BID_TYPE_CUSTOM'];

    public function __construct(
        private readonly CampaignWriteService $writes,
        private readonly WriteAdapterRegistry $adapters,
        private readonly ProjectContext $context,
    ) {}

    public function options(Request $request, string $project, string $campaign): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);
        $model = UnifiedCampaign::query()->findOrFail($campaign);

        return ApiResponse::success($this->writes->options($request->user(), $this->projectId($project), $model), 'Campaign write options.');
    }

    public function perform(Request $request, string $project, string $campaign): JsonResponse
    {
        $model = UnifiedCampaign::query()->findOrFail($campaign);
        $base = $request->validate([
            'level' => ['required', Rule::enum(WriteLevel::class)],
            'entity_id' => ['required', 'string', 'max:64'],
            'action' => ['required', Rule::enum(WriteAction::class)],
        ]);
        $level = WriteLevel::from($base['level']);
        $action = WriteAction::from($base['action']);

        if ($action->destructive()) {
            $request->validate(['confirm' => ['required', 'accepted']], ['confirm.*' => 'A destructive change needs an explicit confirmation.']);
        }

        $input = $this->input($request, $level, $action, (string) $base['entity_id'], $model);
        $result = $this->writes->perform($request->user(), $this->projectId($project), $model, $level, (string) $base['entity_id'], $action, $input);

        return response()->json(['data' => $result['body'], 'message' => ($result['body']['ok'] ?? false) ? 'Provider confirmed the change.' : 'The change was not made.'], $result['status']);
    }

    public function create(Request $request, string $project, string $campaign): JsonResponse
    {
        $model = UnifiedCampaign::query()->findOrFail($campaign);
        $data = $request->validate([
            'external_account_id' => ['required', 'string', 'max:64'],
            'objective' => ['required', 'string', 'max:64'],
            'daily_budget' => ['nullable', 'numeric', 'gt:0', 'max:100000000'],
            'launch' => ['sometimes', 'boolean'],
        ]);

        $result = $this->writes->create(
            $request->user(),
            $this->projectId($project),
            $model,
            (string) $data['external_account_id'],
            (string) $data['objective'],
            isset($data['daily_budget']) ? (float) $data['daily_budget'] : null,
            (bool) ($data['launch'] ?? false),
        );

        return response()->json(['data' => $result['body'], 'message' => ($result['body']['ok'] ?? false) ? 'Created on the platform.' : 'The campaign was not created.'], $result['status']);
    }

    /** @return array<string, mixed> */
    private function input(Request $request, WriteLevel $level, WriteAction $action, string $entityId, UnifiedCampaign $campaign): array
    {
        $provider = $this->provider($level, $entityId, $campaign);
        $adapter = $provider !== null ? $this->adapters->for($provider) : null;

        return match ($action) {
            WriteAction::Rename => $request->validate(['name' => ['required', 'string', 'min:1', 'max:250']]),
            WriteAction::Budget => $this->budget($request, $adapter?->budgetKinds($level) ?? []),
            WriteAction::Schedule => $this->schedule($request),
            WriteAction::BidStrategy => $this->strategy($request, $adapter?->bidStrategies($level) ?? []),
            WriteAction::CreateAdSet => $this->adSet($request, $provider, $adapter?->optimizationGoals() ?? []),
            WriteAction::CreateAd => $request->validate(['name' => ['required', 'string', 'min:1', 'max:250']])
                + ($provider === 'google' ? $this->searchAd($request) : $this->creative($request, $provider)),
            WriteAction::Creative => $this->creative($request, $provider),
            WriteAction::Targeting => $this->targeting($request),
            WriteAction::Placements => $this->placements($request, $adapter?->placementFamilies() ?? []),
            WriteAction::Destination => $request->validate(['url' => ['required', 'url:https', 'max:2048']]),
            default => [],
        };
    }

    /**
     * @param  list<string>  $kinds
     * @return array<string, mixed>
     */
    private function budget(Request $request, array $kinds): array
    {
        $data = $request->validate([
            'daily_budget' => ['nullable', 'numeric', 'gt:0', 'max:100000000'],
            'lifetime_budget' => ['nullable', 'numeric', 'gt:0', 'max:1000000000'],
        ]);
        $given = array_keys(array_filter($data, static fn ($v) => $v !== null));
        abort_unless(count($given) === 1, 422, 'State exactly one budget — daily or lifetime.');
        $kind = $given[0] === 'daily_budget' ? 'daily' : 'lifetime';
        abort_unless(in_array($kind, $kinds, true), 422, "This platform does not take a {$kind} budget here.");

        return [$given[0] => (float) $data[$given[0]]];
    }

    /** @return array<string, mixed> */
    private function schedule(Request $request): array
    {
        $data = $request->validate([
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at', 'after:now'],
        ]);
        abort_if(($data['starts_at'] ?? null) === null && ($data['ends_at'] ?? null) === null, 422, 'State a start, an end, or both.');

        return array_filter($data, static fn ($v) => $v !== null);
    }

    /**
     * @param  list<string>  $strategies
     * @return array<string, mixed>
     */
    private function strategy(Request $request, array $strategies): array
    {
        $data = $request->validate([
            'strategy' => ['required', 'string', Rule::in($strategies)],
            'bid_amount' => ['nullable', 'numeric', 'gt:0', 'max:10000000'],
        ]);
        abort_if(in_array($data['strategy'], self::CAP_STRATEGIES, true) && ($data['bid_amount'] ?? null) === null, 422, 'This strategy needs a bid amount.');

        return array_filter($data, static fn ($v) => $v !== null);
    }

    /**
     * @param  list<string>  $goals
     * @return array<string, mixed>
     */
    private function adSet(Request $request, ?string $provider, array $goals): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:250'],
            'optimization_goal' => $goals === [] ? ['prohibited'] : ['required', 'string', Rule::in($goals)],
            'daily_budget' => ['nullable', 'numeric', 'gt:0', 'max:100000000'],
            'countries' => $provider === 'google' ? ['prohibited'] : ['required', 'array', 'min:1', 'max:25'],
            'countries.*' => ['string', 'regex:/^[A-Z]{2}$/'],
            'bid_amount' => $provider === 'google' ? ['required', 'numeric', 'gt:0', 'max:10000000'] : ['nullable', 'numeric', 'gt:0'],
            'starts_at' => ['nullable', 'date'],
        ]);

        return array_filter($data, static fn ($v) => $v !== null);
    }

    /** @return array<string, mixed> the provider's own id for a creative this project holds */
    private function creative(Request $request, ?string $provider): array
    {
        $data = $request->validate(['creative_id' => ['required', 'string', 'max:64']]);
        $external = ExternalCreative::query()->where('provider', $provider)->whereKey($data['creative_id'])->value('external_creative_id');
        abort_if($external === null || $external === '', 422, 'That creative is not one of this project\'s on this platform.');

        return ['creative_external_id' => (string) $external];
    }

    /**
     * A Google responsive search ad, within Google's own limits: 3 to 15 headlines of up to 30
     * characters, 2 to 4 descriptions of up to 90, and an https landing URL.
     *
     * @return array<string, mixed>
     */
    private function searchAd(Request $request): array
    {
        $data = $request->validate([
            'headlines' => ['required', 'array', 'min:3', 'max:15'],
            'headlines.*' => ['required', 'string', 'distinct', 'max:30'],
            'descriptions' => ['required', 'array', 'min:2', 'max:4'],
            'descriptions.*' => ['required', 'string', 'distinct', 'max:90'],
            'url' => ['required', 'url:https', 'max:2048'],
        ]);

        return ['headlines' => array_values($data['headlines']), 'descriptions' => array_values($data['descriptions']), 'url' => (string) $data['url']];
    }

    /** @return array<string, mixed> */
    private function targeting(Request $request): array
    {
        $data = $request->validate([
            'countries' => ['required', 'array', 'min:1', 'max:25'],
            'countries.*' => ['string', 'regex:/^[A-Z]{2}$/'],
            'age_min' => ['nullable', 'integer', 'min:13', 'max:65'],
            'age_max' => ['nullable', 'integer', 'min:13', 'max:65', 'gte:age_min'],
            'genders' => ['nullable', Rule::in(['all', 'male', 'female'])],
        ]);

        return array_filter($data, static fn ($v) => $v !== null);
    }

    /**
     * @param  list<string>  $families
     * @return array<string, mixed>
     */
    private function placements(Request $request, array $families): array
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['automatic', 'custom'])],
            'platforms' => ['required_if:mode,custom', 'array', 'min:1'],
            'platforms.*' => ['string', Rule::in($families)],
        ]);

        return array_filter($data, static fn ($v) => $v !== null);
    }

    private function provider(WriteLevel $level, string $entityId, UnifiedCampaign $campaign): ?string
    {
        $query = match ($level) {
            WriteLevel::Campaign => ExternalCampaign::query(),
            WriteLevel::AdSet => ExternalAdSet::query(),
            WriteLevel::Ad => ExternalAd::query(),
        };
        $provider = $query->where('unified_campaign_id', $campaign->id)->whereKey($entityId)->value('provider');

        return $provider !== null ? (string) $provider : null;
    }

    private function projectId(string $project): string
    {
        return $this->context->projectId() ?? $project;
    }
}
