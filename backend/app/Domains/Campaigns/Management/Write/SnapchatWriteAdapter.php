<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Management\Write;

use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Support\PlatformHttp;
use App\Domains\Integrations\Support\ProviderErrorText;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;

/**
 * Snapchat Marketing API — an update is a PUT of the WHOLE entity under its parent.
 *
 * So every change is read-modify-write: the entity is read as Snapchat holds it now, the one field
 * is changed, and the result is put back. Writing a partial object would reset whatever it left
 * out. A 200 is not the verdict on its own: Snapchat reports each entity's outcome in
 * `sub_request_status`, and a refused item inside a successful envelope is a refusal.
 *
 * Provider semantics: removal is DELETE; there is no archive and no copy endpoint; bids live on the
 * ad squad, not the campaign; an objective is fixed at creation.
 */
final class SnapchatWriteAdapter extends AbstractWriteAdapter
{
    protected const SUPPORT = [
        'campaign' => [
            'pause' => true, 'resume' => true, 'rename' => true, 'budget' => true, 'schedule' => true, 'delete' => true,
            'create_ad_set' => true,
            'bid_strategy' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'archive' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'duplicate' => WriteRefusal::PROVIDER_UNSUPPORTED,
        ],
        'ad_set' => [
            'pause' => true, 'resume' => true, 'rename' => true, 'budget' => true, 'schedule' => true, 'bid_strategy' => true, 'delete' => true,
            'targeting' => true, 'create_ad' => true, 'placements' => true,
            'archive' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'duplicate' => WriteRefusal::PROVIDER_UNSUPPORTED,
        ],
        'ad' => [
            'pause' => true, 'resume' => true, 'rename' => true, 'delete' => true, 'creative' => true,
            // The swipe-up URL is part of the creative on Snapchat; bind another creative to change it.
            'destination' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'budget' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'schedule' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'bid_strategy' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'archive' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'duplicate' => WriteRefusal::PROVIDER_UNSUPPORTED,
        ],
    ];

    /** Fields Snapchat returns on read and rejects on write. */
    private const READ_ONLY = ['created_at', 'updated_at', 'delivery_status', 'review_status', 'review_status_reasons', 'effective_status'];

    /** level → [read path prefix, response key, entity key] */
    private const SHAPE = [
        'campaign' => ['campaigns', 'campaigns', 'campaign'],
        'ad_set' => ['adsquads', 'adsquads', 'adsquad'],
        'ad' => ['ads', 'ads', 'ad'],
    ];

    public function provider(): string
    {
        return 'snapchat';
    }

    public function bidStrategies(WriteLevel $level): array
    {
        return $level === WriteLevel::AdSet ? ['AUTO_BID', 'LOWEST_COST_WITH_MAX_BID', 'TARGET_COST'] : [];
    }

    public function budgetKinds(WriteLevel $level): array
    {
        return $level === WriteLevel::Ad ? [] : ['daily', 'lifetime'];
    }

    public function objectives(): array
    {
        return ['AWARENESS_AND_ENGAGEMENT', 'TRAFFIC', 'LEADS', 'APP_PROMOTION', 'SALES'];
    }

    public function createRefusal(): ?string
    {
        return null;
    }

    /**
     * Snapchat's placement positions (`placement_v2.snapchat_positions`), as Snap documents them.
     * Two of them cannot stand alone: Spotlight interstitials, and Chat Feed.
     */
    private const POSITIONS = ['interstitial_user', 'interstitial_content', 'interstitial_spotlight', 'instream', 'public_stories_instream', 'chat_feed', 'feed', 'camera'];

    private const NOT_ALONE = ['interstitial_spotlight', 'chat_feed'];

    public function placementFamilies(): array
    {
        return self::POSITIONS;
    }

    public function optimizationGoals(): array
    {
        return ['IMPRESSIONS', 'SWIPES', 'VIDEO_VIEWS', 'PIXEL_PURCHASE', 'PIXEL_SIGNUP'];
    }

    public function perform(ApiAdvertisingConnector $connector, WriteTarget $target, WriteAction $action, array $input): WriteOutcome
    {
        [$path, $responseKey, $entityKey] = self::SHAPE[$target->level->value];

        if ($action === WriteAction::Delete) {
            return $this->itemVerdict($this->send($connector, 'DELETE', "{$path}/{$target->externalId}"), $responseKey, $this->statusMirror($action));
        }

        if ($action === WriteAction::CreateAdSet) {
            $response = $this->send($connector, 'POST', "campaigns/{$target->externalId}/adsquads", ['adsquads' => [array_filter([
                'campaign_id' => $target->externalId,
                'name' => (string) $input['name'],
                'type' => 'SNAP_ADS',
                'placement_v2' => ['config' => 'AUTOMATIC'],
                'optimization_goal' => (string) $input['optimization_goal'],
                'bid_strategy' => 'AUTO_BID',
                'daily_budget_micro' => isset($input['daily_budget']) ? $this->micros((float) $input['daily_budget']) : null,
                'targeting' => ['geos' => array_map(static fn (string $c): array => ['country_code' => strtolower($c)], array_values((array) $input['countries']))],
                'status' => 'PAUSED',
                'start_time' => Carbon::parse((string) ($input['starts_at'] ?? 'now'))->toIso8601String(),
            ], static fn ($v) => $v !== null)]], idempotent: false);
            $id = ($response->json() ?? [])['adsquads'][0]['adsquad']['id'] ?? null;

            return $this->itemVerdict($response, 'adsquads', [], is_scalar($id) ? (string) $id : null);
        }

        if ($action === WriteAction::CreateAd) {
            $response = $this->send($connector, 'POST', "adsquads/{$target->externalId}/ads", ['ads' => [[
                'ad_squad_id' => $target->externalId,
                'creative_id' => (string) $input['creative_external_id'],
                'name' => (string) $input['name'],
                'type' => 'SNAP_AD',
                'status' => 'PAUSED',
            ]]], idempotent: false);
            $id = ($response->json() ?? [])['ads'][0]['ad']['id'] ?? null;

            return $this->itemVerdict($response, 'ads', [], is_scalar($id) ? (string) $id : null);
        }

        if ($action === WriteAction::Placements && ($input['mode'] ?? 'automatic') === 'custom'
            && array_diff(array_values((array) $input['platforms']), self::NOT_ALONE) === []) {
            return WriteOutcome::refused('Snapchat does not run Spotlight interstitials or Chat Feed on their own; choose at least one other position with them.');
        }

        $read = $this->send($connector, 'GET', "{$path}/{$target->externalId}");
        if (! PlatformHttp::succeeded($read)) {
            return $this->verdict($read);
        }
        $entity = (array) ((($read->json() ?? [])[$responseKey][0][$entityKey]) ?? []);
        if ($entity === []) {
            return WriteOutcome::refused('Snapchat returned no entity to update.');
        }
        foreach (self::READ_ONLY as $field) {
            unset($entity[$field]);
        }

        [$changes, $mirror] = $this->changes($target->level, $action, $input, $entity);

        return $this->itemVerdict(
            $this->send($connector, 'PUT', $this->parentPath($target, $entity), [$responseKey => [array_merge($entity, $changes)]]),
            $responseKey,
            $mirror,
        );
    }

    public function create(ApiAdvertisingConnector $connector, CreateCampaignCommand $command): WriteOutcome
    {
        $response = $this->send($connector, 'POST', "adaccounts/{$command->accountExternalId}/campaigns", ['campaigns' => [array_filter([
            'name' => $command->name,
            'ad_account_id' => $command->accountExternalId,
            'status' => 'PAUSED',
            'start_time' => Carbon::now()->toIso8601String(),
            'objective_v2_properties' => ['objective_v2_type' => $command->objective],
            'daily_budget_micro' => $command->dailyBudget !== null ? $this->micros($command->dailyBudget) : null,
        ], static fn ($v) => $v !== null)]], idempotent: false);

        $id = ($response->json() ?? [])['campaigns'][0]['campaign']['id'] ?? null;

        return $this->itemVerdict($response, 'campaigns', [], is_scalar($id) ? (string) $id : null);
    }

    /**
     * @param  array<string, mixed>  $entity  the entity as Snapchat holds it now
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function changes(WriteLevel $level, WriteAction $action, array $input, array $entity): array
    {
        if ($action === WriteAction::Targeting) {
            // Only geos and demographics are replaced; every other targeting key Snapchat holds is kept.
            $targeting = (array) ($entity['targeting'] ?? []);
            $targeting['geos'] = array_map(static fn (string $c): array => ['country_code' => strtolower($c)], array_values((array) $input['countries']));
            $genders = (string) ($input['genders'] ?? 'all');
            $targeting['demographics'] = [array_filter([
                'min_age' => isset($input['age_min']) ? (string) $input['age_min'] : null,
                'max_age' => isset($input['age_max']) ? (string) $input['age_max'] : null,
                'gender' => $genders === 'all' ? null : strtoupper($genders),
            ], static fn ($v) => $v !== null)];

            return [['targeting' => $targeting], ['targeting' => $this->targetingMirror($input)]];
        }
        if ($action === WriteAction::Placements) {
            $automatic = ($input['mode'] ?? 'automatic') === 'automatic';
            $positions = $automatic ? [] : array_values((array) $input['platforms']);
            $current = (array) ($entity['placement_v2'] ?? []);
            // AUTOMATIC takes no other property. CUSTOM keeps the content inclusions only while in-stream is still chosen.
            $placement = $automatic ? ['config' => 'AUTOMATIC'] : array_filter([
                'config' => 'CUSTOM',
                'platforms' => ['SNAPCHAT'],
                'snapchat_positions' => array_map('strtoupper', $positions),
                'inclusion' => in_array('instream', $positions, true) ? ($current['inclusion'] ?? null) : null,
                'exclusion' => in_array('instream', $positions, true) ? ($current['exclusion'] ?? null) : null,
            ], static fn ($v) => $v !== null);

            return [['placement_v2' => $placement], ['targeting' => ['placement_config' => $automatic ? 'automatic' : 'custom', 'placements' => $positions]]];
        }
        if ($action === WriteAction::Creative) {
            return [['creative_id' => (string) $input['creative_external_id']], ['creative_external_id' => (string) $input['creative_external_id']]];
        }

        return match ($action) {
            WriteAction::Pause => [['status' => 'PAUSED'], $this->statusMirror($action)],
            WriteAction::Resume => [['status' => 'ACTIVE'], $this->statusMirror($action)],
            WriteAction::Rename => [['name' => (string) $input['name']], ['name' => (string) $input['name']]],
            WriteAction::Budget => [array_filter([
                'daily_budget_micro' => isset($input['daily_budget']) ? $this->micros((float) $input['daily_budget']) : null,
                $level === WriteLevel::Campaign ? 'lifetime_spend_cap_micro' : 'lifetime_budget_micro' => isset($input['lifetime_budget']) ? $this->micros((float) $input['lifetime_budget']) : null,
            ], static fn ($v) => $v !== null), $this->budgetMirror($input)],
            WriteAction::Schedule => [array_filter([
                'start_time' => isset($input['starts_at']) ? Carbon::parse((string) $input['starts_at'])->toIso8601String() : null,
                'end_time' => isset($input['ends_at']) ? Carbon::parse((string) $input['ends_at'])->toIso8601String() : null,
            ], static fn ($v) => $v !== null), array_filter([
                'starts_at' => $input['starts_at'] ?? null,
                'ends_at' => $input['ends_at'] ?? null,
            ], static fn ($v) => $v !== null)],
            WriteAction::BidStrategy => [array_filter([
                'bid_strategy' => (string) $input['strategy'],
                'bid_micro' => isset($input['bid_amount']) ? $this->micros((float) $input['bid_amount']) : null,
            ], static fn ($v) => $v !== null), ['bid_strategy' => (string) $input['strategy']]],
            default => [[], []],
        };
    }

    /** @param  array<string, mixed>  $entity */
    private function parentPath(WriteTarget $target, array $entity): string
    {
        return match ($target->level) {
            WriteLevel::Campaign => "adaccounts/{$target->accountExternalId}/campaigns",
            WriteLevel::AdSet => 'campaigns/'.($entity['campaign_id'] ?? $target->parentExternalId).'/adsquads',
            WriteLevel::Ad => 'adsquads/'.($entity['ad_squad_id'] ?? $target->parentExternalId).'/ads',
        };
    }

    /** @param  array<string, mixed>  $mirror */
    private function itemVerdict(Response $response, string $responseKey, array $mirror, ?string $newExternalId = null): WriteOutcome
    {
        $outcome = $this->verdict($response, $mirror, $newExternalId);
        if (! $outcome->ok) {
            return $outcome;
        }

        $item = ($response->json() ?? [])[$responseKey][0] ?? null;
        if (is_array($item) && isset($item['sub_request_status']) && strtoupper((string) $item['sub_request_status']) !== 'SUCCESS') {
            return WriteOutcome::refused(
                ProviderErrorText::forStorage((string) ($item['sub_request_error_reason'] ?? 'Snapchat refused the change.')) ?? 'Snapchat refused the change.',
                $outcome->requestId,
            );
        }

        return $outcome;
    }
}
