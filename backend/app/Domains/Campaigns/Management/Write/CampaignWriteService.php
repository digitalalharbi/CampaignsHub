<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Management\Write;

use App\Domains\Audit\AuditLogger;
use App\Domains\Campaigns\Management\WriteCapabilityRegistry;
use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * CAMPAIGN-MGMT-WRITE-001 — the one road from a button to a provider.
 *
 * UI → permission → capability registry → provider adapter → real provider API → response →
 * audit → mirror. Every gate is here, in this order, and nothing reaches an adapter without passing
 * all of them:
 *
 *   1. the entity is read from THIS project's mirror of THIS campaign (global project and tenant
 *      scopes apply), so an id from another project is simply not found;
 *   2. demo rows are refused — they exist on no platform;
 *   3. the adapter must implement the action, and the registry must not be awaiting credentials;
 *   4. the reader must hold the capability's permission;
 *   5. the ad account must be SELECTED for this project (ACCOUNT-SCOPE-ISOLATION-001) — an account
 *      the workspace can see but this project has not chosen is not this project's to change;
 *   6. the account's connection must be connected.
 *
 * Then the provider is called, and only what the provider CONFIRMED is written to the local mirror.
 * Every attempt — confirmed or refused — is recorded with who, what, the provider and its answer,
 * which is what the campaign's change history reads.
 */
final class CampaignWriteService
{
    /** Columns a confirmed write may touch on each mirror. Anything else in an outcome is ignored. */
    private const MIRRORED = [
        ExternalCampaign::class => ['status', 'name', 'daily_budget', 'lifetime_budget', 'starts_at', 'ends_at'],
        ExternalAdSet::class => ['status', 'name', 'daily_budget', 'lifetime_budget', 'starts_at', 'ends_at', 'bid_strategy', 'targeting'],
        ExternalAd::class => ['status', 'name', 'destination_url', 'creative_id'],
    ];

    public function __construct(
        private readonly WriteAdapterRegistry $adapters,
        private readonly AdvertisingConnectorRegistry $connectors,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Every entity under the campaign, with each action's state — `available` or the reason it is not.
     *
     * @return array<string, mixed>
     */
    public function options(User $user, string $projectId, UnifiedCampaign $campaign): array
    {
        $selected = BoundAccountVisibility::activeAccountIds($projectId);
        $externals = ExternalCampaign::query()->where('unified_campaign_id', $campaign->id)->orderBy('name')->get();
        $accounts = ExternalAccount::query()->whereIn('id', $externals->pluck('external_account_id')->filter()->unique()->all())->with('connection')->get()->keyBy('id');
        $adSets = $externals->isEmpty() ? collect() : ExternalAdSet::query()->whereIn('external_campaign_id', $externals->pluck('id'))->orderBy('name')->get();
        $ads = $externals->isEmpty() ? collect() : ExternalAd::query()->whereIn('external_campaign_id', $externals->pluck('id'))->orderBy('name')->get();
        $byId = $externals->keyBy('id');

        $entities = [];
        foreach ($externals as $e) {
            $entities[] = $this->describe($user, WriteLevel::Campaign, $e, $e, $accounts[$e->external_account_id] ?? null, $selected, null);
        }
        foreach ($adSets as $s) {
            $parent = $byId[$s->external_campaign_id] ?? null;
            $entities[] = $this->describe($user, WriteLevel::AdSet, $s, $parent, $parent ? ($accounts[$parent->external_account_id] ?? null) : null, $selected, $s->external_campaign_id);
        }
        foreach ($ads as $a) {
            $parent = $byId[$a->external_campaign_id] ?? null;
            $entities[] = $this->describe($user, WriteLevel::Ad, $a, $parent, $parent ? ($accounts[$parent->external_account_id] ?? null) : null, $selected, $a->external_ad_set_id);
        }

        return ['entities' => $entities, 'create' => $this->createOptions($user, $projectId), 'creatives' => $this->creatives($externals->pluck('provider')->unique()->values()->all())];
    }

    /**
     * @param  array<string, mixed>  $input  validated for this action by the controller
     * @return array{status: int, body: array<string, mixed>}
     */
    public function perform(User $user, string $projectId, UnifiedCampaign $campaign, WriteLevel $level, string $entityId, WriteAction $action, array $input): array
    {
        [$entity, $external, $parentExternalId] = $this->resolve($campaign, $level, $entityId);
        $account = $external?->external_account_id ? ExternalAccount::query()->with('connection')->find($external->external_account_id) : null;

        $refusal = $this->refusal($user, (string) $entity->provider, $level, $action, $account, BoundAccountVisibility::activeAccountIds($projectId), (bool) ($entity->is_demo ?? false));
        if ($refusal !== null) {
            return ['status' => $refusal === WriteRefusal::NO_PERMISSION ? 403 : 409, 'body' => ['refusal' => $refusal, 'message' => $this->refusalText($refusal)]];
        }

        $adapter = $this->adapters->for((string) $entity->provider);
        $connector = $this->connector($account);
        $target = new WriteTarget(
            level: $level,
            provider: (string) $entity->provider,
            externalId: (string) $entity->external_id,
            accountExternalId: (string) $account->external_id,
            parentExternalId: $parentExternalId,
            managerExternalId: $account->parent_external_id !== null ? (string) $account->parent_external_id : null,
            currency: $entity->currency ?? $account->currency,
        );

        $original = $entity->only(self::MIRRORED[$entity::class]);
        $outcome = $adapter->perform($connector, $target, $action, $input);

        $mirror = $this->mirrorFrom($entity, $outcome->mirror);
        // What the change replaced — only the fields it touched, so the history reads «from → to».
        $before = array_intersect_key($original, $mirror);
        if ($outcome->ok && $mirror !== []) {
            $entity->forceFill($mirror)->save();
        }
        $child = null;
        if ($outcome->ok && $action->createsChild() && $outcome->newExternalId !== null) {
            $child = $this->mirrorChild($campaign, $projectId, $entity, $action, $outcome->newExternalId, $input);
        }

        $this->record($campaign, $outcome, [
            'level' => $level->value, 'action' => $action->value, 'provider' => $entity->provider,
            'entity_id' => (string) $entity->getKey(), 'external_id' => (string) $entity->external_id, 'name' => $entity->name,
            'account' => $account->name, 'values' => $before,
        ], ['input' => $input, 'values' => $mirror]);

        return $outcome->ok
            ? ['status' => 200, 'body' => [
                'ok' => true, 'level' => $level->value, 'action' => $action->value, 'provider' => $entity->provider,
                'entity_id' => (string) $entity->getKey(), 'mirror' => $mirror,
                'new_external_id' => $outcome->newExternalId, 'request_id' => $outcome->requestId,
                'child_id' => $child !== null ? (string) $child->getKey() : null,
            ]]
            : ['status' => $outcome->httpStatus, 'body' => ['ok' => false, 'refusal' => 'provider_refused', 'message' => $outcome->message, 'request_id' => $outcome->requestId]];
    }

    /**
     * Push the campaign to a provider account: create it there PAUSED, mirror it linked to this
     * campaign, and — only when asked and permitted — launch it.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function create(User $user, string $projectId, UnifiedCampaign $campaign, string $accountId, string $objective, ?float $dailyBudget, bool $launch): array
    {
        $account = ExternalAccount::query()->with('connection')->find($accountId);
        $refusal = $this->createRefusal($user, $projectId, $account, $launch);
        if ($refusal !== null) {
            return ['status' => $refusal === WriteRefusal::NO_PERMISSION ? 403 : 409, 'body' => ['refusal' => $refusal, 'message' => $this->refusalText($refusal)]];
        }

        $adapter = $this->adapters->for((string) $account->provider);
        if (! in_array($objective, $adapter->objectives(), true)) {
            return ['status' => 422, 'body' => ['refusal' => 'invalid_objective', 'message' => 'That objective is not one this platform offers.']];
        }

        $connector = $this->connector($account);
        $outcome = $adapter->create($connector, new CreateCampaignCommand(
            provider: (string) $account->provider,
            accountExternalId: (string) $account->external_id,
            managerExternalId: $account->parent_external_id !== null ? (string) $account->parent_external_id : null,
            currency: $account->currency,
            name: (string) $campaign->name,
            objective: $objective,
            dailyBudget: $dailyBudget,
        ));

        $mirror = null;
        $launched = false;
        $launchMessage = null;
        if ($outcome->ok && $outcome->newExternalId !== null) {
            $mirror = ExternalCampaign::query()->create([
                'tenant_id' => $campaign->tenant_id,
                'project_id' => $projectId,
                'client_workspace_id' => $campaign->client_workspace_id,
                'unified_campaign_id' => $campaign->id,
                'external_account_id' => $account->id,
                'provider' => $account->provider,
                'external_id' => $outcome->newExternalId,
                'name' => $campaign->name,
                'status' => 'paused',
                'objective' => $objective,
                'daily_budget' => $dailyBudget,
                'currency' => $account->currency,
                'linked_at' => now(),
                'linked_by' => $user->getKey(),
            ]);

            if ($launch) {
                $resume = $adapter->perform($connector, new WriteTarget(WriteLevel::Campaign, (string) $account->provider, $outcome->newExternalId, (string) $account->external_id, null, $account->parent_external_id, $account->currency), WriteAction::Resume, []);
                $launched = $resume->ok;
                $launchMessage = $resume->ok ? null : $resume->message;
                if ($resume->ok) {
                    $mirror->forceFill(['status' => 'active'])->save();
                }
            }
        }

        $this->record($campaign, $outcome, [
            'level' => 'campaign', 'action' => 'create', 'provider' => $account->provider, 'account' => $account->name,
        ], ['objective' => $objective, 'daily_budget' => $dailyBudget, 'launch' => $launch, 'launched' => $launched, 'launch_message' => $launchMessage], 'campaign.provider_created');

        return $outcome->ok
            ? ['status' => 201, 'body' => [
                'ok' => true, 'provider' => $account->provider, 'external_id' => $outcome->newExternalId,
                'entity_id' => $mirror ? (string) $mirror->getKey() : null, 'launched' => $launched, 'launch_message' => $launchMessage,
                'request_id' => $outcome->requestId,
            ]]
            : ['status' => $outcome->httpStatus, 'body' => ['ok' => false, 'refusal' => 'provider_refused', 'message' => $outcome->message, 'request_id' => $outcome->requestId]];
    }

    /**
     * The confirmed changes, in the mirror's own columns: targeting is MERGED into what the mirror holds
     * (the adapter changed only some keys), and a provider creative id becomes the local creative row.
     *
     * @param  array<string, mixed>  $confirmed
     * @return array<string, mixed>
     */
    private function mirrorFrom(Model $entity, array $confirmed): array
    {
        if (isset($confirmed['targeting']) && is_array($confirmed['targeting'])) {
            $confirmed['targeting'] = array_merge((array) ($entity->getAttribute('targeting') ?? []), $confirmed['targeting']);
        }
        if (isset($confirmed['creative_external_id'])) {
            $local = ExternalCreative::query()
                ->where('provider', $entity->getAttribute('provider'))
                ->where('external_creative_id', (string) $confirmed['creative_external_id'])
                ->value('id');
            unset($confirmed['creative_external_id']);
            if ($local !== null) {
                $confirmed['creative_id'] = (string) $local;
            }
        }

        return array_intersect_key($confirmed, array_flip(self::MIRRORED[$entity::class]));
    }

    /**
     * The new child the platform confirmed, mirrored PAUSED under its parent so it appears at once —
     * the next structure sync then reads it as the platform holds it.
     *
     * @param  array<string, mixed>  $input
     */
    private function mirrorChild(UnifiedCampaign $campaign, string $projectId, Model $parent, WriteAction $action, string $externalId, array $input): Model
    {
        if ($action === WriteAction::CreateAdSet) {
            return ExternalAdSet::query()->create([
                'tenant_id' => $campaign->tenant_id,
                'project_id' => $projectId,
                'external_campaign_id' => $parent->getKey(),
                'unified_campaign_id' => $campaign->id,
                'provider' => $parent->getAttribute('provider'),
                'external_id' => $externalId,
                'name' => (string) $input['name'],
                'status' => 'paused',
                'optimization_goal' => $input['optimization_goal'] ?? null,
                'daily_budget' => $input['daily_budget'] ?? null,
                'currency' => $parent->getAttribute('currency'),
                'targeting' => isset($input['countries']) ? ['countries' => array_values((array) $input['countries'])] : null,
            ]);
        }

        return ExternalAd::query()->create([
            'tenant_id' => $campaign->tenant_id,
            'project_id' => $projectId,
            'external_ad_set_id' => $parent->getKey(),
            'external_campaign_id' => $parent->getAttribute('external_campaign_id'),
            'unified_campaign_id' => $campaign->id,
            'provider' => $parent->getAttribute('provider'),
            'external_id' => $externalId,
            'name' => (string) $input['name'],
            'status' => 'paused',
            'creative_id' => isset($input['creative_external_id'])
                ? ExternalCreative::query()->where('provider', $parent->getAttribute('provider'))->where('external_creative_id', (string) $input['creative_external_id'])->value('id')
                : null,
        ]);
    }

    /** @return array{0: ExternalCampaign|ExternalAdSet|ExternalAd, 1: ?ExternalCampaign, 2: ?string} */
    private function resolve(UnifiedCampaign $campaign, WriteLevel $level, string $entityId): array
    {
        $entity = match ($level) {
            WriteLevel::Campaign => ExternalCampaign::query()->where('unified_campaign_id', $campaign->id)->find($entityId),
            WriteLevel::AdSet => ExternalAdSet::query()->where('unified_campaign_id', $campaign->id)->find($entityId),
            WriteLevel::Ad => ExternalAd::query()->where('unified_campaign_id', $campaign->id)->find($entityId),
        };
        abort_if($entity === null, 404, 'That entity is not part of this campaign.');

        $external = $entity instanceof ExternalCampaign ? $entity : ExternalCampaign::query()->find($entity->external_campaign_id);
        $parentExternalId = match (true) {
            $entity instanceof ExternalAdSet => $external?->external_id,
            $entity instanceof ExternalAd => $entity->external_ad_set_id !== null
                ? ExternalAdSet::query()->whereKey($entity->external_ad_set_id)->value('external_id')
                : null,
            default => null,
        };

        return [$entity, $external, $parentExternalId !== null ? (string) $parentExternalId : null];
    }

    /** @param  list<string>  $selected */
    private function refusal(User $user, string $provider, WriteLevel $level, WriteAction $action, ?ExternalAccount $account, array $selected, bool $demo): ?string
    {
        $adapter = $this->adapters->for($provider);
        if ($adapter === null) {
            return WriteRefusal::NOT_IMPLEMENTED;
        }
        // The platform's own limits first: they are true of a demo row too, and say more than «demo».
        $refusal = $adapter->refusal($level, $action);
        if ($refusal !== null) {
            return $refusal;
        }
        if ($demo) {
            return 'demo';
        }
        $capability = $action->capability();
        $entry = collect(WriteCapabilityRegistry::entries())->first(fn (array $e) => $e['provider'] === $provider && $e['capability'] === $capability);
        if (($entry['status'] ?? null) === WriteCapabilityRegistry::AWAITING_CREDENTIALS) {
            return WriteRefusal::AWAITING_CREDENTIALS;
        }
        if (! WriteCapabilityRegistry::allowed($provider, $capability, static fn (string $p): bool => $user->hasPermission($p))) {
            return $user->hasPermission(WriteCapabilityRegistry::CAPABILITIES[$capability]) ? WriteRefusal::NOT_IMPLEMENTED : WriteRefusal::NO_PERMISSION;
        }

        return $this->accountRefusal($account, $selected);
    }

    private function createRefusal(User $user, string $projectId, ?ExternalAccount $account, bool $launch): ?string
    {
        if ($account === null) {
            return WriteRefusal::ACCOUNT_NOT_SELECTED;
        }
        $adapter = $this->adapters->for((string) $account->provider);
        if ($adapter === null || $adapter->createRefusal() !== null) {
            return $adapter?->createRefusal() ?? WriteRefusal::NOT_IMPLEMENTED;
        }
        $entry = collect(WriteCapabilityRegistry::entries())->first(fn (array $e) => $e['provider'] === $account->provider && $e['capability'] === 'create_campaign');
        if (($entry['status'] ?? null) === WriteCapabilityRegistry::AWAITING_CREDENTIALS) {
            return WriteRefusal::AWAITING_CREDENTIALS;
        }
        $can = static fn (string $p): bool => $user->hasPermission($p);
        if (! WriteCapabilityRegistry::allowed((string) $account->provider, 'create_campaign', $can) || ($launch && ! $user->hasPermission(WriteCapabilityRegistry::CAPABILITIES['publish']))) {
            return WriteRefusal::NO_PERMISSION;
        }

        return $this->accountRefusal($account, BoundAccountVisibility::activeAccountIds($projectId));
    }

    /** @param  list<string>  $selected */
    private function accountRefusal(?ExternalAccount $account, array $selected): ?string
    {
        if ($account === null || ! in_array((string) $account->id, $selected, true)) {
            return WriteRefusal::ACCOUNT_NOT_SELECTED;
        }
        if ($account->connection === null) {
            return WriteRefusal::NOT_CONNECTED;
        }
        $connector = $this->connectors->get((string) $account->provider);
        if (! $connector instanceof ApiAdvertisingConnector) {
            return WriteRefusal::NOT_IMPLEMENTED;
        }

        return match ($connector->withConnection($account->connection)->status()) {
            ConnectorStatus::Connected => null,
            ConnectorStatus::AwaitingCredentials => WriteRefusal::AWAITING_CREDENTIALS,
            default => WriteRefusal::NOT_CONNECTED,
        };
    }

    private function connector(ExternalAccount $account): ApiAdvertisingConnector
    {
        $connector = $this->connectors->get((string) $account->provider);
        assert($connector instanceof ApiAdvertisingConnector);

        return $connector->withConnection($account->connection);
    }

    /**
     * @param  list<string>  $selected
     * @return array<string, mixed>
     */
    private function describe(User $user, WriteLevel $level, Model $entity, ?ExternalCampaign $parent, ?ExternalAccount $account, array $selected, ?string $parentId): array
    {
        $provider = (string) $entity->getAttribute('provider');
        $adapter = $this->adapters->for($provider);
        $actions = [];
        foreach (WriteAction::cases() as $action) {
            $refusal = $this->refusal($user, $provider, $level, $action, $account, $selected, (bool) ($entity->getAttribute('is_demo') ?? false));
            $actions[$action->value] = $refusal ?? 'available';
        }

        return [
            'id' => (string) $entity->getKey(),
            'level' => $level->value,
            'parent_id' => $parentId,
            'provider' => $provider,
            'external_id' => (string) $entity->getAttribute('external_id'),
            'name' => $entity->getAttribute('name'),
            'status' => $entity->getAttribute('status'),
            'daily_budget' => $entity->getAttribute('daily_budget') !== null ? (float) $entity->getAttribute('daily_budget') : null,
            'lifetime_budget' => $entity->getAttribute('lifetime_budget') !== null ? (float) $entity->getAttribute('lifetime_budget') : null,
            'currency' => $entity->getAttribute('currency') ?? $account?->currency,
            'bid_strategy' => $entity->getAttribute('bid_strategy'),
            'account' => $account ? ['id' => (string) $account->id, 'name' => $account->name, 'external_id' => $account->external_id] : null,
            'actions' => $actions,
            'budget_kinds' => $adapter?->budgetKinds($level) ?? [],
            'bid_strategies' => $adapter?->bidStrategies($level) ?? [],
            'optimization_goals' => $level === WriteLevel::Campaign ? ($adapter?->optimizationGoals() ?? []) : [],
            'placement_families' => $level === WriteLevel::AdSet ? ($adapter?->placementFamilies() ?? []) : [],
            'targeting' => $level === WriteLevel::AdSet ? $entity->getAttribute('targeting') : null,
            'destination_url' => $level === WriteLevel::Ad ? $entity->getAttribute('destination_url') : null,
        ];
    }

    /**
     * The creatives an ad can be bound to, per platform: this project's own, with a platform id.
     *
     * @param  list<string>  $providers
     * @return array<string, list<array<string, mixed>>>
     */
    private function creatives(array $providers): array
    {
        $out = [];
        foreach ($providers as $provider) {
            $out[$provider] = ExternalCreative::query()
                ->where('provider', $provider)
                ->whereNotNull('external_creative_id')
                ->orderByDesc('last_active_at')
                ->limit(60)
                ->get(['id', 'name', 'format', 'thumbnail_url', 'external_creative_id'])
                ->map(fn (ExternalCreative $c) => ['id' => (string) $c->id, 'name' => $c->name, 'format' => $c->format, 'thumbnail_url' => $c->thumbnail_url])
                ->values()->all();
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function createOptions(User $user, string $projectId): array
    {
        $selected = BoundAccountVisibility::activeAccountIds($projectId);
        if ($selected === []) {
            return [];
        }

        return ExternalAccount::query()->whereIn('id', $selected)->with('connection')->orderBy('provider')->orderBy('name')->get()
            ->map(fn (ExternalAccount $a) => [
                'id' => (string) $a->id,
                'provider' => $a->provider,
                'name' => $a->name,
                'currency' => $a->currency,
                'state' => $this->createRefusal($user, $projectId, $a, false) ?? 'available',
                'launch_permitted' => $user->hasPermission(WriteCapabilityRegistry::CAPABILITIES['publish']),
                'objectives' => $this->adapters->for((string) $a->provider)?->objectives() ?? [],
            ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function record(UnifiedCampaign $campaign, WriteOutcome $outcome, array $before, array $after, string $confirmedAction = 'campaign.provider_write'): void
    {
        $this->audit->log(
            action: $outcome->ok ? $confirmedAction : 'campaign.provider_write_refused',
            entityType: UnifiedCampaign::class,
            entityId: (string) $campaign->id,
            before: $before,
            after: $after + ['provider_request_id' => $outcome->requestId, 'new_external_id' => $outcome->newExternalId],
            reason: $outcome->ok ? null : $outcome->message,
        );
    }

    private function refusalText(string $refusal): string
    {
        return match ($refusal) {
            WriteRefusal::PROVIDER_UNSUPPORTED => 'The platform does not allow this change through its API.',
            WriteRefusal::NOT_IMPLEMENTED => 'CampaignsHub does not perform this change on this platform yet.',
            WriteRefusal::AWAITING_CREDENTIALS => 'This platform is awaiting its app credentials on this installation.',
            WriteRefusal::NOT_CONNECTED => 'The ad account’s connection is not authorised — reconnect it in Integrations.',
            WriteRefusal::ACCOUNT_NOT_SELECTED => 'This ad account is not selected for this project.',
            WriteRefusal::NO_PERMISSION => 'You do not have the permission this change needs.',
            'demo' => 'Demo data exists on no platform — there is nothing to change.',
            default => 'This change is not available.',
        };
    }
}
