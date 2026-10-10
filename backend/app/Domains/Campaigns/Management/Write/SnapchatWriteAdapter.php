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
            'pause' => true, 'resume' => true, 'rename' => true, 'delete' => true,
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

    public function perform(ApiAdvertisingConnector $connector, WriteTarget $target, WriteAction $action, array $input): WriteOutcome
    {
        [$path, $responseKey, $entityKey] = self::SHAPE[$target->level->value];

        if ($action === WriteAction::Delete) {
            return $this->itemVerdict($this->send($connector, 'DELETE', "{$path}/{$target->externalId}"), $responseKey, $this->statusMirror($action));
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

        [$changes, $mirror] = $this->changes($target->level, $action, $input);

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

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function changes(WriteLevel $level, WriteAction $action, array $input): array
    {
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
