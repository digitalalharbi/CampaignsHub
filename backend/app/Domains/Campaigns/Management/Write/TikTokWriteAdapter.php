<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Management\Write;

use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Support\PlatformHttp;
use Illuminate\Support\Carbon;

/**
 * TikTok Marketing API — status changes are their own endpoints taking id LISTS; edits are
 * `…/update/` calls naming the advertiser. Failure arrives as HTTP 200 with a non-zero `code`, which
 * `PlatformHttp::succeeded` already reads.
 *
 * Provider semantics: removal is `operation_status: DELETE`; there is no archive and no copy
 * endpoint; a campaign has no schedule of its own (ad groups do); bids live on the ad group; an
 * ad cannot be renamed without re-sending its whole creative set, which is not done from here.
 * A budget keeps the entity's own budget MODE — TikTok changes the amount, not daily-versus-total.
 *
 * Targeting names places by TikTok LOCATION ids, not ISO codes, so a country list is turned into ids
 * by asking TikTok itself (`tool/region/` at country level, for the campaign's objective and the ad
 * group's placements). A code TikTok does not list is refused by name — never dropped, never guessed.
 * Age is targeted in TikTok's fixed bands; a range is honoured by the bands that lie inside it.
 *
 * An ad group is created here for the two goals whose billing TikTok fixes without a pixel or an
 * app: CLICK (billed per click) and REACH (billed per thousand). It is created switched off. Ad
 * creation needs an identity and an uploaded video, which this layer does not hold, so it stays
 * not implemented and says so.
 */
final class TikTokWriteAdapter extends AbstractWriteAdapter
{
    protected const SUPPORT = [
        'campaign' => [
            'pause' => true, 'resume' => true, 'rename' => true, 'budget' => true, 'delete' => true,
            'create_ad_set' => true,
            'schedule' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'bid_strategy' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'archive' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'duplicate' => WriteRefusal::PROVIDER_UNSUPPORTED,
        ],
        'ad_set' => [
            'pause' => true, 'resume' => true, 'rename' => true, 'budget' => true, 'schedule' => true, 'bid_strategy' => true, 'delete' => true,
            'targeting' => true, 'placements' => true,
            'archive' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'duplicate' => WriteRefusal::PROVIDER_UNSUPPORTED,
        ],
        'ad' => [
            'pause' => true, 'resume' => true, 'delete' => true,
            'budget' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'schedule' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'bid_strategy' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'archive' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'duplicate' => WriteRefusal::PROVIDER_UNSUPPORTED,
        ],
    ];

    /** level → [status endpoint, id-list key, update endpoint, id key, name key] */
    private const SHAPE = [
        'campaign' => ['campaign/status/update/', 'campaign_ids', 'campaign/update/', 'campaign_id', 'campaign_name'],
        'ad_set' => ['adgroup/status/update/', 'adgroup_ids', 'adgroup/update/', 'adgroup_id', 'adgroup_name'],
        'ad' => ['ad/status/update/', 'ad_ids', 'ad/update/', 'ad_id', 'ad_name'],
    ];

    public function provider(): string
    {
        return 'tiktok';
    }

    public function bidStrategies(WriteLevel $level): array
    {
        return $level === WriteLevel::AdSet ? ['BID_TYPE_NO_BID', 'BID_TYPE_CUSTOM'] : [];
    }

    public function budgetKinds(WriteLevel $level): array
    {
        return $level === WriteLevel::Ad ? [] : ['daily', 'lifetime'];
    }

    public function objectives(): array
    {
        return ['REACH', 'TRAFFIC', 'VIDEO_VIEWS', 'ENGAGEMENT', 'LEAD_GENERATION', 'APP_PROMOTION', 'WEB_CONVERSIONS', 'PRODUCT_SALES'];
    }

    public function createRefusal(): ?string
    {
        return null;
    }

    /** Goal → what TikTok bills for it. Only goals whose billing needs no pixel or app are offered. */
    private const GOAL_BILLING = ['CLICK' => 'CPC', 'REACH' => 'CPM'];

    /** placement family (as offered) → TikTok token */
    private const PLACEMENTS = ['tiktok' => 'PLACEMENT_TIKTOK', 'pangle' => 'PLACEMENT_PANGLE', 'global_app_bundle' => 'PLACEMENT_GLOBAL_APP_BUNDLE'];

    /** TikTok's age bands as [token, from, to]. */
    private const AGE_BANDS = [
        ['AGE_13_17', 13, 17], ['AGE_18_24', 18, 24], ['AGE_25_34', 25, 34],
        ['AGE_35_44', 35, 44], ['AGE_45_54', 45, 54], ['AGE_55_100', 55, 100],
    ];

    public function optimizationGoals(): array
    {
        return array_keys(self::GOAL_BILLING);
    }

    public function placementFamilies(): array
    {
        return array_keys(self::PLACEMENTS);
    }

    public function perform(ApiAdvertisingConnector $connector, WriteTarget $target, WriteAction $action, array $input): WriteOutcome
    {
        [$statusPath, $idsKey, $updatePath, $idKey, $nameKey] = self::SHAPE[$target->level->value];
        $advertiser = $target->accountExternalId;

        $status = match ($action) {
            WriteAction::Pause => 'DISABLE',
            WriteAction::Resume => 'ENABLE',
            WriteAction::Delete => 'DELETE',
            default => null,
        };
        if ($status !== null) {
            return $this->verdict($this->send($connector, 'POST', $statusPath, [
                'advertiser_id' => $advertiser, $idsKey => [$target->externalId], 'operation_status' => $status,
            ]), $this->statusMirror($action));
        }

        if ($action === WriteAction::CreateAdSet) {
            return $this->createAdGroup($connector, $target, $input);
        }

        if ($action === WriteAction::Targeting) {
            return $this->targeting($connector, $target, $input);
        }

        if ($action === WriteAction::Placements) {
            $automatic = ($input['mode'] ?? 'automatic') === 'automatic';
            $families = $automatic ? [] : array_values((array) $input['platforms']);

            return $this->verdict($this->send($connector, 'POST', 'adgroup/update/', array_filter([
                'advertiser_id' => $advertiser,
                'adgroup_id' => $target->externalId,
                'placement_type' => $automatic ? 'PLACEMENT_TYPE_AUTOMATIC' : 'PLACEMENT_TYPE_NORMAL',
                'placements' => $automatic ? null : array_map(static fn (string $f): string => self::PLACEMENTS[$f], $families),
            ], static fn ($v) => $v !== null)), ['targeting' => ['placement_config' => $automatic ? 'automatic' : 'custom', 'placements' => $families]]);
        }

        if ($action === WriteAction::Budget && $target->level === WriteLevel::AdSet) {
            $amount = (float) ($input['daily_budget'] ?? $input['lifetime_budget']);

            return $this->verdict($this->send($connector, 'POST', 'adgroup/budget/update/', [
                'advertiser_id' => $advertiser, 'budget' => [['adgroup_id' => $target->externalId, 'budget' => $amount]],
            ]), $this->budgetMirror($input));
        }

        [$fields, $mirror] = match ($action) {
            WriteAction::Rename => [[$nameKey => (string) $input['name']], ['name' => (string) $input['name']]],
            WriteAction::Budget => [['budget' => (float) ($input['daily_budget'] ?? $input['lifetime_budget'])], $this->budgetMirror($input)],
            WriteAction::Schedule => [$this->schedule($input), array_filter([
                'starts_at' => $input['starts_at'] ?? null,
                'ends_at' => $input['ends_at'] ?? null,
            ], static fn ($v) => $v !== null)],
            WriteAction::BidStrategy => [array_filter([
                'bid_type' => (string) $input['strategy'],
                'bid_price' => isset($input['bid_amount']) ? (float) $input['bid_amount'] : null,
            ], static fn ($v) => $v !== null), ['bid_strategy' => (string) $input['strategy']]],
            default => [[], []],
        };

        return $this->verdict($this->send($connector, 'POST', $updatePath, ['advertiser_id' => $advertiser, $idKey => $target->externalId] + $fields), $mirror);
    }

    public function create(ApiAdvertisingConnector $connector, CreateCampaignCommand $command): WriteOutcome
    {
        $response = $this->send($connector, 'POST', 'campaign/create/', array_filter([
            'advertiser_id' => $command->accountExternalId,
            'campaign_name' => $command->name,
            'objective_type' => $command->objective,
            'budget_mode' => $command->dailyBudget !== null ? 'BUDGET_MODE_DAY' : 'BUDGET_MODE_INFINITE',
            'budget' => $command->dailyBudget,
            'operation_status' => 'DISABLE',
        ], static fn ($v) => $v !== null), idempotent: false);
        $id = ($response->json() ?? [])['data']['campaign_id'] ?? null;

        return $this->verdict($response, [], is_scalar($id) ? (string) $id : null);
    }

    /** @param  array<string, mixed>  $input */
    private function createAdGroup(ApiAdvertisingConnector $connector, WriteTarget $target, array $input): WriteOutcome
    {
        $goal = (string) $input['optimization_goal'];
        $placements = ['PLACEMENT_TIKTOK'];
        [$ids, $refusal] = $this->locationIds($connector, $target->accountExternalId, $target->externalId, $placements, (array) $input['countries']);
        if ($refusal !== null) {
            return $refusal;
        }

        $response = $this->send($connector, 'POST', 'adgroup/create/', array_filter([
            'advertiser_id' => $target->accountExternalId,
            'campaign_id' => $target->externalId,
            'adgroup_name' => (string) $input['name'],
            'placement_type' => 'PLACEMENT_TYPE_AUTOMATIC',
            'location_ids' => $ids,
            'budget_mode' => isset($input['daily_budget']) ? 'BUDGET_MODE_DAY' : null,
            'budget' => isset($input['daily_budget']) ? (float) $input['daily_budget'] : null,
            ...$this->schedule(['starts_at' => (string) ($input['starts_at'] ?? Carbon::now()->toIso8601String())]),
            'optimization_goal' => $goal,
            'billing_event' => self::GOAL_BILLING[$goal],
            'bid_type' => 'BID_TYPE_NO_BID',
            'pacing' => 'PACING_MODE_SMOOTH',
            'operation_status' => 'DISABLE',
        ], static fn ($v) => $v !== null), idempotent: false);
        $id = ($response->json() ?? [])['data']['adgroup_id'] ?? null;

        return $this->verdict($response, [], is_scalar($id) ? (string) $id : null);
    }

    /** @param  array<string, mixed>  $input */
    private function targeting(ApiAdvertisingConnector $connector, WriteTarget $target, array $input): WriteOutcome
    {
        $read = $this->send($connector, 'GET', 'adgroup/get/', [
            'advertiser_id' => $target->accountExternalId,
            'filtering' => json_encode(['adgroup_ids' => [$target->externalId]], JSON_THROW_ON_ERROR),
        ]);
        if (! PlatformHttp::succeeded($read)) {
            return $this->verdict($read);
        }
        $group = (array) ((($read->json() ?? [])['data']['list'][0]) ?? []);
        if ($group === []) {
            return WriteOutcome::refused('TikTok returned no ad group to update.');
        }
        $placements = array_values(array_filter((array) ($group['placements'] ?? []), 'is_string'));
        $campaignId = (string) ($group['campaign_id'] ?? $target->parentExternalId);

        [$ids, $refusal] = $this->locationIds($connector, $target->accountExternalId, $campaignId, $placements === [] ? ['PLACEMENT_TIKTOK'] : $placements, (array) $input['countries']);
        if ($refusal !== null) {
            return $refusal;
        }

        $min = isset($input['age_min']) ? (int) $input['age_min'] : null;
        $max = isset($input['age_max']) ? (int) $input['age_max'] : null;
        $bands = null;
        if ($min !== null || $max !== null) {
            $bands = array_values(array_filter(self::AGE_BANDS, static fn (array $b): bool => $b[1] >= ($min ?? 13) && ($max === null || $max >= 65 || $b[2] <= $max)));
            if ($bands === []) {
                return WriteOutcome::refused('TikTok targets age in fixed bands (13-17, 18-24, 25-34, 35-44, 45-54, 55+); no band lies inside the range chosen.');
            }
        }
        $genders = (string) ($input['genders'] ?? 'all');

        $mirror = $this->targetingMirror($input);
        if ($bands !== null) {
            $last = $bands[count($bands) - 1][2];
            $mirror['age'] = $bands[0][1].'-'.($last >= 100 ? 65 : $last);
        }

        return $this->verdict($this->send($connector, 'POST', 'adgroup/update/', array_filter([
            'advertiser_id' => $target->accountExternalId,
            'adgroup_id' => $target->externalId,
            'location_ids' => $ids,
            'age_groups' => $bands === null ? null : array_map(static fn (array $b): string => $b[0], $bands),
            'gender' => match ($genders) {
                'male' => 'GENDER_MALE',
                'female' => 'GENDER_FEMALE',
                default => 'GENDER_UNLIMITED',
            },
        ], static fn ($v) => $v !== null)), ['targeting' => $mirror]);
    }

    /**
     * ISO country codes → TikTok location ids, as TikTok lists them for this campaign's objective and
     * these placements. Any code TikTok does not list refuses the whole write, naming the code.
     *
     * @param  list<string>  $placements
     * @param  array<int, mixed>  $countries
     * @return array{0: list<string>, 1: ?WriteOutcome}
     */
    private function locationIds(ApiAdvertisingConnector $connector, string $advertiser, string $campaignId, array $placements, array $countries): array
    {
        $campaign = $this->send($connector, 'GET', 'campaign/get/', [
            'advertiser_id' => $advertiser,
            'filtering' => json_encode(['campaign_ids' => [$campaignId]], JSON_THROW_ON_ERROR),
        ]);
        if (! PlatformHttp::succeeded($campaign)) {
            return [[], $this->verdict($campaign)];
        }
        $objective = (($campaign->json() ?? [])['data']['list'][0]['objective_type']) ?? null;
        if (! is_string($objective) || $objective === '') {
            return [[], WriteOutcome::refused('TikTok returned no objective for the campaign, so its locations cannot be looked up.')];
        }

        $regions = $this->send($connector, 'GET', 'tool/region/', [
            'advertiser_id' => $advertiser,
            'placements' => json_encode($placements, JSON_THROW_ON_ERROR),
            'objective_type' => $objective,
            'level_range' => 'TO_COUNTRY',
        ]);
        if (! PlatformHttp::succeeded($regions)) {
            return [[], $this->verdict($regions)];
        }

        $byCode = [];
        foreach ((array) (($regions->json() ?? [])['data']['region_info'] ?? []) as $region) {
            if (is_array($region) && isset($region['region_code'], $region['location_id'])) {
                $byCode[strtoupper((string) $region['region_code'])] ??= (string) $region['location_id'];
            }
        }

        $ids = [];
        $missing = [];
        foreach ($countries as $code) {
            $code = strtoupper((string) $code);
            if (isset($byCode[$code])) {
                $ids[] = $byCode[$code];
            } else {
                $missing[] = $code;
            }
        }
        if ($missing !== []) {
            return [[], WriteOutcome::refused('TikTok does not offer these countries for this campaign: '.implode(', ', $missing).'.')];
        }

        return [$ids, null];
    }

    /** @return array<string, string> */
    private function schedule(array $input): array
    {
        $fmt = static fn (string $v): string => Carbon::parse($v)->utc()->format('Y-m-d H:i:s');
        $start = isset($input['starts_at']) ? $fmt((string) $input['starts_at']) : null;
        $end = isset($input['ends_at']) ? $fmt((string) $input['ends_at']) : null;

        return array_filter([
            'schedule_type' => $end !== null ? 'SCHEDULE_START_END' : 'SCHEDULE_FROM_NOW',
            'schedule_start_time' => $start,
            'schedule_end_time' => $end,
        ], static fn ($v) => $v !== null);
    }
}
