<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Management\Write;

use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Support\PlatformHttp;
use Illuminate\Support\Carbon;

/**
 * Meta Marketing API — every node is addressed by its own id (`POST /{id}`), and a change is a
 * field set on that node.
 *
 * Provider semantics kept as Meta defines them: ARCHIVED keeps the entity readable, DELETED does
 * not, and both are offered; budgets are in the currency's smallest unit; copies are made with
 * `/{id}/copies` and land PAUSED so a duplicate can never start spending by itself. An objective
 * cannot change after creation on Meta, so no such action exists here.
 */
final class MetaWriteAdapter extends AbstractWriteAdapter
{
    protected const SUPPORT = [
        'campaign' => [
            'pause' => true, 'resume' => true, 'rename' => true, 'budget' => true, 'bid_strategy' => true,
            'archive' => true, 'delete' => true, 'duplicate' => true, 'create_ad_set' => true,
        ],
        'ad_set' => [
            'pause' => true, 'resume' => true, 'rename' => true, 'budget' => true, 'schedule' => true,
            'bid_strategy' => true, 'archive' => true, 'delete' => true, 'duplicate' => true,
            'targeting' => true, 'placements' => true, 'create_ad' => true,
        ],
        'ad' => [
            'pause' => true, 'resume' => true, 'rename' => true, 'archive' => true, 'delete' => true, 'duplicate' => true,
            'budget' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'schedule' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'bid_strategy' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'creative' => true,
            // The landing URL lives in the creative, and a Meta creative cannot be edited — bind another one instead.
            'destination' => WriteRefusal::PROVIDER_UNSUPPORTED,
        ],
    ];

    /** Placement fields Meta keeps inside `targeting`; cleared together so «automatic» really is automatic. */
    private const PLACEMENT_KEYS = ['publisher_platforms', 'facebook_positions', 'instagram_positions', 'audience_network_positions', 'messenger_positions'];

    private const STRATEGIES = ['LOWEST_COST_WITHOUT_CAP', 'LOWEST_COST_WITH_BID_CAP', 'COST_CAP'];

    public function provider(): string
    {
        return 'meta';
    }

    public function bidStrategies(WriteLevel $level): array
    {
        return $level === WriteLevel::Ad ? [] : self::STRATEGIES;
    }

    public function budgetKinds(WriteLevel $level): array
    {
        return $level === WriteLevel::Ad ? [] : ['daily', 'lifetime'];
    }

    public function objectives(): array
    {
        return ['OUTCOME_AWARENESS', 'OUTCOME_TRAFFIC', 'OUTCOME_ENGAGEMENT', 'OUTCOME_LEADS', 'OUTCOME_APP_PROMOTION', 'OUTCOME_SALES'];
    }

    public function createRefusal(): ?string
    {
        return null;
    }

    public function optimizationGoals(): array
    {
        return ['LINK_CLICKS', 'LANDING_PAGE_VIEWS', 'OFFSITE_CONVERSIONS', 'LEAD_GENERATION', 'REACH', 'IMPRESSIONS', 'THRUPLAY'];
    }

    public function placementFamilies(): array
    {
        return ['facebook', 'instagram', 'audience_network', 'messenger'];
    }

    public function perform(ApiAdvertisingConnector $connector, WriteTarget $target, WriteAction $action, array $input): WriteOutcome
    {
        $node = $target->externalId;

        if ($action === WriteAction::Duplicate) {
            $copy = $this->send($connector, 'POST', "{$node}/copies", array_filter([
                'deep_copy' => $target->level === WriteLevel::Ad ? null : true,
                'status_option' => 'PAUSED',
            ], static fn ($v) => $v !== null), idempotent: false);
            $body = $copy->json() ?? [];
            $id = $body['copied_campaign_id'] ?? $body['copied_adset_id'] ?? $body['copied_ad_id'] ?? null;

            return $this->verdict($copy, [], is_scalar($id) ? (string) $id : null);
        }

        if ($action === WriteAction::CreateAdSet) {
            $response = $this->send($connector, 'POST', $this->account($target->accountExternalId).'/adsets', array_filter([
                'name' => (string) $input['name'],
                'campaign_id' => $node,
                'status' => 'PAUSED',
                'billing_event' => 'IMPRESSIONS',
                'optimization_goal' => (string) $input['optimization_goal'],
                'daily_budget' => isset($input['daily_budget']) ? $this->minorUnits((float) $input['daily_budget'], $target->currency) : null,
                'targeting' => ['geo_locations' => ['countries' => array_values((array) $input['countries'])]],
                'start_time' => isset($input['starts_at']) ? Carbon::parse((string) $input['starts_at'])->toIso8601String() : null,
            ], static fn ($v) => $v !== null), idempotent: false);
            $id = ($response->json() ?? [])['id'] ?? null;

            return $this->verdict($response, [], is_scalar($id) ? (string) $id : null);
        }

        if ($action === WriteAction::CreateAd) {
            $response = $this->send($connector, 'POST', $this->account($target->accountExternalId).'/ads', [
                'name' => (string) $input['name'],
                'adset_id' => $node,
                'creative' => ['creative_id' => (string) $input['creative_external_id']],
                'status' => 'PAUSED',
            ], idempotent: false);
            $id = ($response->json() ?? [])['id'] ?? null;

            return $this->verdict($response, [], is_scalar($id) ? (string) $id : null);
        }

        if ($action === WriteAction::Targeting || $action === WriteAction::Placements) {
            // Meta replaces `targeting` WHOLE, so it is read first: a write of three keys would erase interests and audiences.
            $read = $this->send($connector, 'GET', $node, ['fields' => 'targeting']);
            if (! PlatformHttp::succeeded($read)) {
                return $this->verdict($read);
            }
            $targeting = (array) (($read->json() ?? [])['targeting'] ?? []);

            if ($action === WriteAction::Targeting) {
                $targeting['geo_locations'] = ['countries' => array_values((array) $input['countries'])];
                $targeting['age_min'] = (int) ($input['age_min'] ?? 18);
                $targeting['age_max'] = (int) ($input['age_max'] ?? 65);
                $genders = (string) ($input['genders'] ?? 'all');
                if ($genders === 'all') {
                    unset($targeting['genders']);
                } else {
                    $targeting['genders'] = [$genders === 'male' ? 1 : 2];
                }
                $mirror = ['targeting' => $this->targetingMirror($input)];
            } else {
                foreach (self::PLACEMENT_KEYS as $key) {
                    unset($targeting[$key]);
                }
                $automatic = ($input['mode'] ?? 'automatic') === 'automatic';
                if (! $automatic) {
                    $targeting['publisher_platforms'] = array_values((array) $input['platforms']);
                }
                $mirror = ['targeting' => ['placement_config' => $automatic ? 'automatic' : 'custom', 'placements' => $automatic ? [] : array_values((array) $input['platforms'])]];
            }

            return $this->verdict($this->send($connector, 'POST', $node, ['targeting' => $targeting]), $mirror);
        }

        if ($action === WriteAction::Creative) {
            return $this->verdict($this->send($connector, 'POST', $node, ['creative' => ['creative_id' => (string) $input['creative_external_id']]]), ['creative_external_id' => (string) $input['creative_external_id']]);
        }

        if ($action === WriteAction::Destination) {
            // Never reached through the service, which refuses it first; stated here so the adapter cannot be misused either.
            return WriteOutcome::refused('Meta keeps the landing URL in the creative, which cannot be edited — bind another creative.');
        }

        [$fields, $mirror] = match ($action) {
            WriteAction::Pause => [['status' => 'PAUSED'], $this->statusMirror($action)],
            WriteAction::Resume => [['status' => 'ACTIVE'], $this->statusMirror($action)],
            WriteAction::Archive => [['status' => 'ARCHIVED'], $this->statusMirror($action)],
            WriteAction::Delete => [['status' => 'DELETED'], $this->statusMirror($action)],
            WriteAction::Rename => [['name' => (string) $input['name']], ['name' => (string) $input['name']]],
            WriteAction::Budget => [array_filter([
                'daily_budget' => isset($input['daily_budget']) ? $this->minorUnits((float) $input['daily_budget'], $target->currency) : null,
                'lifetime_budget' => isset($input['lifetime_budget']) ? $this->minorUnits((float) $input['lifetime_budget'], $target->currency) : null,
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
                'bid_amount' => isset($input['bid_amount']) ? $this->minorUnits((float) $input['bid_amount'], $target->currency) : null,
            ], static fn ($v) => $v !== null), $target->level === WriteLevel::AdSet ? ['bid_strategy' => (string) $input['strategy']] : []],
        };

        return $this->verdict($this->send($connector, 'POST', $node, $fields), $mirror);
    }

    public function create(ApiAdvertisingConnector $connector, CreateCampaignCommand $command): WriteOutcome
    {
        $account = $this->account($command->accountExternalId);

        $response = $this->send($connector, 'POST', "{$account}/campaigns", array_filter([
            'name' => $command->name,
            'objective' => $command->objective,
            'status' => 'PAUSED',
            'special_ad_categories' => [],
            'daily_budget' => $command->dailyBudget !== null ? $this->minorUnits($command->dailyBudget, $command->currency) : null,
            'bid_strategy' => $command->dailyBudget !== null ? 'LOWEST_COST_WITHOUT_CAP' : null,
        ], static fn ($v) => $v !== null), idempotent: false);
        $id = ($response->json() ?? [])['id'] ?? null;

        return $this->verdict($response, [], is_scalar($id) ? (string) $id : null);
    }

    private function account(string $id): string
    {
        return str_starts_with($id, 'act_') ? $id : 'act_'.$id;
    }
}
