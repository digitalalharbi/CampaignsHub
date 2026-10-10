<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Management\Write;

use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
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
            'archive' => true, 'delete' => true, 'duplicate' => true,
        ],
        'ad_set' => [
            'pause' => true, 'resume' => true, 'rename' => true, 'budget' => true, 'schedule' => true,
            'bid_strategy' => true, 'archive' => true, 'delete' => true, 'duplicate' => true,
        ],
        'ad' => [
            'pause' => true, 'resume' => true, 'rename' => true, 'archive' => true, 'delete' => true, 'duplicate' => true,
            'budget' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'schedule' => WriteRefusal::PROVIDER_UNSUPPORTED,
            'bid_strategy' => WriteRefusal::PROVIDER_UNSUPPORTED,
        ],
    ];

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
        $account = str_starts_with($command->accountExternalId, 'act_') ? $command->accountExternalId : 'act_'.$command->accountExternalId;

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
}
