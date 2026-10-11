<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Management\Write;

use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
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
 */
final class TikTokWriteAdapter extends AbstractWriteAdapter
{
    protected const SUPPORT = [
        'campaign' => [
            'pause' => true, 'resume' => true, 'rename' => true, 'budget' => true, 'delete' => true,
            'schedule' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'bid_strategy' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'archive' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'duplicate' => WriteRefusal::PROVIDER_UNSUPPORTED,
        ],
        'ad_set' => [
            'pause' => true, 'resume' => true, 'rename' => true, 'budget' => true, 'schedule' => true, 'bid_strategy' => true, 'delete' => true,
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
