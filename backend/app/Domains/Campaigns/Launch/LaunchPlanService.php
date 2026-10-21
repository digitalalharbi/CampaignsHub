<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Launch;

use App\Domains\Audit\AuditLogger;
use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Management\Write\CampaignWriteService;
use App\Domains\Campaigns\Management\Write\WriteAction;
use App\Domains\Campaigns\Management\Write\WriteAdapterRegistry;
use App\Domains\Campaigns\Management\Write\WriteLevel;
use App\Domains\Campaigns\Management\WriteCapabilityRegistry;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * LAUNCH-001 — one plan, published through the SAME write services a campaign's control tab uses.
 *
 * Nothing here talks to a provider directly. Each step is `CampaignWriteService::create` or `::perform`, so
 * every refusal the registry, the permission set, the account selection and the connection status would raise
 * on the control tab is raised here too, and every confirmed write is mirrored and audited the same way.
 *
 * What the plan adds is ORDER and MEMORY: campaign → ad set → ad → run, per account, each step keeping the
 * provider's id or its error. A step with an id is never sent again — that is what makes a retry after a
 * partial launch safe — and a campaign is created paused and run only when the plan says so and the person
 * holds the launch permission. No provider call is made before an explicit confirmation.
 */
final class LaunchPlanService
{
    public function __construct(
        private readonly CampaignWriteService $writes,
        private readonly WriteAdapterRegistry $adapters,
        private readonly AuditLogger $audit,
    ) {}

    /** What the builder may offer: the project's accounts with their write state, and each platform's vocabulary. */
    public function options(User $user, string $projectId): array
    {
        $accounts = $this->writes->accountOptions($user, $projectId);
        $providers = [];
        foreach (array_unique(array_column($accounts, 'provider')) as $provider) {
            $adapter = $this->adapters->for((string) $provider);
            if ($adapter === null) {
                continue;
            }
            $providers[] = [
                'provider' => $provider,
                'objectives' => $adapter->objectives(),
                'optimization_goals' => $adapter->optimizationGoals(),
                'bid_strategies' => $adapter->bidStrategies(WriteLevel::AdSet),
                'placement_families' => $adapter->placementFamilies(),
                'ad_set_refusal' => $adapter->refusal(WriteLevel::Campaign, WriteAction::CreateAdSet),
                'ad_refusal' => $adapter->refusal(WriteLevel::AdSet, WriteAction::CreateAd),
                'ad_fields' => match ($provider) {
                    'google' => 'search_ad', 'tiktok' => 'tiktok_ad', default => 'creative'
                },
                'countries_in_ad_set' => $provider !== 'google',
                'bid_required' => $provider === 'google',
            ];
        }

        return [
            'accounts' => $accounts,
            'providers' => $providers,
            'creatives' => $this->writes->creativeOptions(array_values(array_unique(array_column($accounts, 'provider')))),
            'launch_permitted' => $user->hasPermission(WriteCapabilityRegistry::CAPABILITIES['publish']),
        ];
    }

    /** Save (or re-save) a draft and say, per account, what publishing would do and what would refuse it. */
    public function prepare(User $user, Project $project, array $data, ?LaunchPlan $plan = null): LaunchPlan
    {
        $spec = LaunchInput::spec($data);
        if ($plan !== null && $plan->status !== LaunchPlan::DRAFT) {
            throw new InvalidArgumentException('Only a draft can be edited — a published plan is a record of what happened.');
        }

        $plan ??= new LaunchPlan;
        $plan->forceFill([
            'tenant_id' => $project->tenant_id,
            'project_id' => $project->getKey(),
            'client_workspace_id' => $project->client_workspace_id,
            'name' => $spec['name'],
            'objective' => $spec['objective'],
            'status' => LaunchPlan::DRAFT,
            'idempotency_key' => $plan->idempotency_key ?? Str::uuid()->toString(),
            'spec' => $spec,
            'steps' => $this->skeleton($spec),
            'launch' => (bool) ($spec['launch'] ?? false),
            'created_by' => $plan->created_by ?? $user->getKey(),
        ])->save();

        return $plan;
    }

    /** The per-account review: state, what each step would send, and what refuses it before anything is sent. */
    public function review(User $user, string $projectId, LaunchPlan $plan): array
    {
        $accounts = collect($this->writes->accountOptions($user, $projectId))->keyBy('id');
        $out = [];
        foreach ($plan->steps as $step) {
            $account = $accounts->get($step['account_id']);
            $provider = (string) ($account['provider'] ?? $step['provider']);
            $adapter = $this->adapters->for($provider);
            $problems = [];
            if ($account === null) {
                $problems[] = 'account_not_selected';
            } elseif ($account['state'] !== 'available') {
                $problems[] = $account['state'];
            } elseif (! in_array(self::objectiveFor($plan, $provider), $account['objectives'], true)) {
                $problems[] = 'invalid_objective';
            }
            if ($adapter !== null && isset($plan->spec['ad_set'])) {
                $refusal = $adapter->refusal(WriteLevel::Campaign, WriteAction::CreateAdSet);
                if ($refusal !== null) {
                    $problems[] = 'ad_set:'.$refusal;
                } else {
                    try {
                        LaunchInput::adSet($plan->spec, $provider, $adapter->optimizationGoals());
                    } catch (ValidationException $e) {
                        $problems[] = 'ad_set:'.implode(' ', array_map(static fn ($m) => implode(' ', $m), $e->errors()));
                    }
                }
            }
            if ($adapter !== null && isset($plan->spec['ad'])) {
                $refusal = $adapter->refusal(WriteLevel::AdSet, WriteAction::CreateAd);
                if ($refusal !== null) {
                    $problems[] = 'ad:'.$refusal;
                } else {
                    try {
                        LaunchInput::ad($plan->spec, $provider);
                    } catch (ValidationException $e) {
                        $problems[] = 'ad:'.implode(' ', array_map(static fn ($m) => implode(' ', $m), $e->errors()));
                    }
                }
            }
            if ($plan->launch && ! $user->hasPermission(WriteCapabilityRegistry::CAPABILITIES['publish'])) {
                $problems[] = 'launch:no_permission';
            }
            $out[] = ['account_id' => $step['account_id'], 'provider' => $provider, 'account_name' => $account['name'] ?? null, 'currency' => $account['currency'] ?? null, 'problems' => $problems, 'step' => $step];
        }

        return $out;
    }

    /**
     * Send the plan to the platforms. Steps that already hold a provider id are skipped, so calling this again
     * after a partial launch finishes the launch instead of starting another.
     */
    public function publish(User $user, Project $project, LaunchPlan $plan): LaunchPlan
    {
        if ($plan->status === LaunchPlan::PUBLISHED) {
            return $plan;
        }
        if (! $user->hasPermission('campaigns.create')) {
            throw new InvalidArgumentException('Publishing needs the campaigns.create permission.');
        }
        if ($plan->launch && ! $user->hasPermission(WriteCapabilityRegistry::CAPABILITIES['publish'])) {
            throw new InvalidArgumentException('Running the campaigns after creation needs the campaigns.launch permission.');
        }

        $campaign = $this->campaignFor($user, $project, $plan);
        $plan->forceFill(['status' => LaunchPlan::PUBLISHING, 'published_by' => $user->getKey()])->save();

        $steps = $plan->steps;
        foreach ($steps as $i => $step) {
            $steps[$i] = $this->runAccount($user, (string) $project->getKey(), $campaign, $plan, $step);
            $plan->forceFill(['steps' => $steps])->save();
        }

        $done = count(array_filter($steps, fn (array $s) => $this->accountDone($plan, $s)));
        $status = $done === count($steps) ? LaunchPlan::PUBLISHED : ($done === 0 && ! $this->anyProgress($steps) ? LaunchPlan::FAILED : LaunchPlan::PARTIAL);
        $plan->forceFill(['status' => $status, 'published_at' => $plan->published_at ?? Carbon::now()])->save();

        $this->audit->log(
            action: 'campaign.launch_'.$status,
            entityType: LaunchPlan::class,
            entityId: (string) $plan->getKey(),
            after: ['campaign_id' => (string) $campaign->getKey(), 'accounts' => count($steps), 'done' => $done, 'launch' => $plan->launch],
        );

        return $plan;
    }

    private function campaignFor(User $user, Project $project, LaunchPlan $plan): UnifiedCampaign
    {
        if ($plan->unified_campaign_id !== null) {
            $existing = UnifiedCampaign::query()->find($plan->unified_campaign_id);
            if ($existing !== null) {
                return $existing;
            }
        }
        $campaign = UnifiedCampaign::create([
            'tenant_id' => $project->tenant_id,
            'project_id' => $project->getKey(),
            'client_workspace_id' => $project->client_workspace_id,
            'name' => $plan->name,
            'objective' => $plan->objective,
            'status' => CampaignStatus::Draft->value,
            'created_by' => $user->getKey(),
            'owner_id' => $user->getKey(),
            'meta' => ['launch_plan_id' => (string) $plan->getKey()],
        ]);
        $plan->forceFill(['unified_campaign_id' => $campaign->getKey()])->save();

        return $campaign;
    }

    private function runAccount(User $user, string $projectId, UnifiedCampaign $campaign, LaunchPlan $plan, array $step): array
    {
        $spec = $plan->spec;
        $provider = (string) $step['provider'];
        $adapter = $this->adapters->for($provider);

        if (($step['campaign']['external_id'] ?? null) === null) {
            $budget = $this->budgetFor($spec, (string) $step['account_id']);
            $result = $this->writes->create($user, $projectId, $campaign, (string) $step['account_id'], self::objectiveFor($plan, $provider), $budget, false);
            $step['campaign'] = $this->outcome($result['body'], 'external_id', 'entity_id');
            if ($step['campaign']['status'] !== 'done') {
                return $step;
            }
        }

        if (isset($spec['ad_set']) && ($step['ad_set']['external_id'] ?? null) === null) {
            try {
                $input = LaunchInput::adSet($spec, $provider, $adapter?->optimizationGoals() ?? []);
            } catch (ValidationException $e) {
                $step['ad_set'] = ['status' => 'failed', 'error' => implode(' ', array_map(static fn ($m) => implode(' ', $m), $e->errors()))];

                return $step;
            }
            $result = $this->writes->perform($user, $projectId, $campaign, WriteLevel::Campaign, (string) $step['campaign']['entity_id'], WriteAction::CreateAdSet, $input);
            $step['ad_set'] = $this->outcome($result['body'], 'new_external_id', 'child_id');
            if ($step['ad_set']['status'] !== 'done') {
                return $step;
            }
        }

        if (isset($spec['ad']) && isset($spec['ad_set']) && ($step['ad']['external_id'] ?? null) === null) {
            try {
                $input = LaunchInput::ad($spec, $provider);
            } catch (ValidationException $e) {
                $step['ad'] = ['status' => 'failed', 'error' => implode(' ', array_map(static fn ($m) => implode(' ', $m), $e->errors()))];

                return $step;
            }
            $result = $this->writes->perform($user, $projectId, $campaign, WriteLevel::AdSet, (string) $step['ad_set']['entity_id'], WriteAction::CreateAd, $input);
            $step['ad'] = $this->outcome($result['body'], 'new_external_id', 'child_id');
            if ($step['ad']['status'] !== 'done') {
                return $step;
            }
        }

        if ($plan->launch && ($step['launched'] ?? null) !== true) {
            $result = $this->writes->perform($user, $projectId, $campaign, WriteLevel::Campaign, (string) $step['campaign']['entity_id'], WriteAction::Resume, []);
            $step['launched'] = (bool) ($result['body']['ok'] ?? false);
            $step['launch_message'] = $step['launched'] ? null : (string) ($result['body']['message'] ?? 'The campaign was created but not run.');
        }

        return $step;
    }

    private function outcome(array $body, string $idKey, string $entityKey): array
    {
        if (($body['ok'] ?? false) === true) {
            return ['status' => 'done', 'external_id' => (string) $body[$idKey], 'entity_id' => $body[$entityKey] ?? null, 'request_id' => $body['request_id'] ?? null, 'error' => null];
        }

        return ['status' => 'failed', 'external_id' => null, 'entity_id' => null, 'request_id' => $body['request_id'] ?? null, 'refusal' => $body['refusal'] ?? null, 'error' => (string) ($body['message'] ?? 'The platform did not accept the step.')];
    }

    private function accountDone(LaunchPlan $plan, array $step): bool
    {
        $spec = $plan->spec;
        if (($step['campaign']['status'] ?? null) !== 'done') {
            return false;
        }
        if (isset($spec['ad_set']) && ($step['ad_set']['status'] ?? null) !== 'done') {
            return false;
        }
        if (isset($spec['ad']) && isset($spec['ad_set']) && ($step['ad']['status'] ?? null) !== 'done') {
            return false;
        }

        return ! $plan->launch || ($step['launched'] ?? false) === true;
    }

    /** @param  list<array<string, mixed>>  $steps */
    private function anyProgress(array $steps): bool
    {
        foreach ($steps as $s) {
            if (($s['campaign']['status'] ?? null) === 'done') {
                return true;
            }
        }

        return false;
    }

    /**
     * Platforms name objectives differently (Meta's OUTCOME_SALES is Snapchat's and TikTok's own word), so a plan
     * may carry one per provider; the plan's own objective is the fallback for a provider it does not name.
     */
    public static function objectiveFor(LaunchPlan $plan, string $provider): string
    {
        $per = (array) ($plan->spec['objectives'] ?? []);

        return (string) ($per[$provider] ?? $plan->objective);
    }

    private function budgetFor(array $spec, string $accountId): ?float
    {
        foreach ((array) ($spec['accounts'] ?? []) as $a) {
            if ((string) $a['external_account_id'] === $accountId) {
                return isset($a['daily_budget']) ? (float) $a['daily_budget'] : null;
            }
        }

        return null;
    }

    /**
     * One step per account, its provider read from the account row itself — never from the request.
     *
     * @return list<array<string, mixed>>
     */
    private function skeleton(array $spec): array
    {
        $ids = array_map(static fn (array $a): string => (string) $a['external_account_id'], array_values((array) $spec['accounts']));
        $providers = ExternalAccount::query()->whereIn('id', $ids)->pluck('provider', 'id');

        return array_map(static fn (array $a) => [
            'account_id' => (string) $a['external_account_id'],
            'provider' => $providers[(string) $a['external_account_id']] ?? null,
            'campaign' => ['status' => 'pending'],
            'ad_set' => isset($spec['ad_set']) ? ['status' => 'pending'] : null,
            'ad' => isset($spec['ad']) ? ['status' => 'pending'] : null,
            'launched' => null,
        ], array_values((array) $spec['accounts']));
    }
}
