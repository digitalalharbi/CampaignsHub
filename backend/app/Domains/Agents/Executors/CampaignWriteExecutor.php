<?php

declare(strict_types=1);

namespace App\Domains\Agents\Executors;

use App\Domains\Agents\Contracts\ProposalExecutor;
use App\Domains\Agents\Models\ActionProposal;
use App\Domains\Agents\Support\ProposalAction;
use App\Domains\Campaigns\Management\Write\CampaignWriteService;
use App\Domains\Campaigns\Management\Write\WriteAction;
use App\Domains\Campaigns\Management\Write\WriteLevel;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Models\User;
use RuntimeException;

/**
 * AGENTS-EXEC-META-001 / AGENTS-EXEC-GOOGLE-001 — an approved proposal is carried out by the SAME write
 * path a person uses on the campaign page, as the person who approved it.
 *
 * Nothing new can reach a platform from here: `CampaignWriteService::perform` applies the approver's
 * permissions, the provider's registered capability for that level and action, the project's bound-account
 * visibility and the demo-row refusal, then sends through the connector's own token and mirrors what the
 * platform confirmed. A refusal there is a failure here, with the refusal's own words.
 *
 * Which providers it may carry is configuration (`agents.execute_providers`), not code: a provider is
 * listed only once its grant includes write access.
 */
final class CampaignWriteExecutor implements ProposalExecutor
{
    public function __construct(private readonly CampaignWriteService $writes) {}

    public function supports(ActionProposal $proposal): bool
    {
        return in_array($proposal->provider, (array) config('agents.execute_providers', []), true)
            && in_array($proposal->target_type, ['campaign', 'ad_set'], true);
    }

    public function execute(ActionProposal $proposal): array
    {
        $approver = User::query()->find($proposal->decided_by);
        if ($approver === null) {
            throw new RuntimeException('approver_missing');
        }

        [$level, $entity] = $this->target($proposal);
        $campaign = UnifiedCampaign::withoutGlobalScopes()->where('project_id', $proposal->project_id)->find($entity->unified_campaign_id);
        if ($campaign === null) {
            throw new RuntimeException('target_not_linked_to_a_campaign');
        }

        [$action, $input] = match (ProposalAction::from($proposal->action)) {
            ProposalAction::Pause => [WriteAction::Pause, []],
            ProposalAction::Resume => [WriteAction::Resume, []],
            ProposalAction::SetDailyBudget => [WriteAction::Budget, ['daily_budget' => (float) ($proposal->payload['daily_budget'] ?? 0)]],
        };

        $result = $this->writes->perform($approver, (string) $proposal->project_id, $campaign, $level, (string) $entity->getKey(), $action, $input);
        $request = ['level' => $level->value, 'action' => $action->value, 'external_id' => $proposal->target_external_id, 'input' => $input];

        if (($result['body']['ok'] ?? false) !== true) {
            // The write path's own refusal (no_permission, provider_unsupported, provider_refused …).
            throw new RuntimeException((string) ($result['body']['refusal'] ?? 'write_refused').': '.mb_substr((string) ($result['body']['message'] ?? ''), 0, 300));
        }

        return ['request' => $request, 'response' => array_intersect_key($result['body'], array_flip(['ok', 'mirror', 'request_id']))];
    }

    /** @return array{0: WriteLevel, 1: ExternalCampaign|ExternalAdSet} */
    private function target(ActionProposal $proposal): array
    {
        $query = $proposal->target_type === 'campaign' ? ExternalCampaign::withoutGlobalScopes() : ExternalAdSet::withoutGlobalScopes();
        $entity = $query->where('project_id', $proposal->project_id)
            ->where('provider', $proposal->provider)
            ->where('external_id', $proposal->target_external_id)
            ->first();

        if ($entity === null) {
            throw new RuntimeException('target_not_found');
        }

        return [$proposal->target_type === 'campaign' ? WriteLevel::Campaign : WriteLevel::AdSet, $entity];
    }
}
