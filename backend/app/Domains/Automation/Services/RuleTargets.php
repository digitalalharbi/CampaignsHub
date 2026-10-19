<?php

declare(strict_types=1);

namespace App\Domains\Automation\Services;

use App\Domains\Automation\Models\AutomationRule;
use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Support\AdPlatforms;

/**
 * The objects a rule may judge: the project's platform objects at the rule's level, on accounts actively
 * bound to the project, narrowed by the rule's scope. Nothing outside the project can be reached — the
 * project is the rule's, never the request's.
 */
final class RuleTargets
{
    /** @return list<array{id: string, level: string, provider: string, external_account_id: ?string, external_id: string, name: ?string, status: ?string, daily_budget: ?float, currency: ?string, started_at: ?string, campaign_id: ?string}> */
    public function for(AutomationRule $rule): array
    {
        $scope = (array) ($rule->scope ?? []);
        // Advertising accounts actively bound to the project — never a store, analytics property or pixel.
        $accounts = ExternalAccount::withoutGlobalScopes()->whereIn('id', BoundAccountVisibility::activeAccountIds((string) $rule->project_id) ?: ['00000000-0000-0000-0000-000000000000'])
            ->where('account_type', 'ad_account')->pluck('id')->map(fn ($v) => (string) $v)->all();
        if (! empty($scope['account_ids'])) {
            $accounts = array_values(array_intersect($accounts, array_map('strval', (array) $scope['account_ids'])));
        }
        $platforms = array_map(static fn ($p): string => AdPlatforms::canonical((string) $p), (array) ($scope['platforms'] ?? []));

        $campaigns = ExternalCampaign::withoutGlobalScopes()->where('project_id', $rule->project_id)
            ->whereIn('external_account_id', $accounts ?: ['00000000-0000-0000-0000-000000000000'])
            ->when($platforms !== [], fn ($q) => $q->whereIn('provider', $platforms))
            ->when(! empty($scope['campaign_ids']), fn ($q) => $q->whereIn('id', (array) $scope['campaign_ids']))
            ->get();

        if ($rule->level === 'campaign') {
            return $campaigns->map(fn (ExternalCampaign $c): array => $this->row('campaign', $c, (string) $c->getKey()))->all();
        }

        $campaignIds = $campaigns->modelKeys();
        $sets = ExternalAdSet::withoutGlobalScopes()->where('project_id', $rule->project_id)->whereIn('external_campaign_id', $campaignIds ?: ['00000000-0000-0000-0000-000000000000'])
            ->when(! empty($scope['ad_set_ids']), fn ($q) => $q->whereIn('id', (array) $scope['ad_set_ids']))
            ->get();
        $accountOf = $campaigns->keyBy(fn ($c) => (string) $c->getKey());

        if ($rule->level === 'ad_set') {
            return $sets->map(fn (ExternalAdSet $s): array => $this->row('ad_set', $s, (string) $s->external_campaign_id, $accountOf[(string) $s->external_campaign_id] ?? null))->all();
        }

        return ExternalAd::withoutGlobalScopes()->where('project_id', $rule->project_id)->whereIn('external_ad_set_id', $sets->modelKeys() ?: ['00000000-0000-0000-0000-000000000000'])
            ->when(! empty($scope['ad_ids']), fn ($q) => $q->whereIn('id', (array) $scope['ad_ids']))
            ->get()
            ->map(fn (ExternalAd $a): array => $this->row('ad', $a, (string) $a->external_campaign_id, $accountOf[(string) $a->external_campaign_id] ?? null))->all();
    }

    private function row(string $level, mixed $m, string $campaignId, ?ExternalCampaign $parent = null): array
    {
        $parent ??= $m instanceof ExternalCampaign ? $m : null;

        return [
            'id' => (string) $m->getKey(), 'level' => $level, 'provider' => AdPlatforms::canonical((string) $m->provider),
            'external_account_id' => $parent?->external_account_id !== null ? (string) $parent->external_account_id : null,
            'external_id' => (string) $m->external_id, 'name' => $m->name, 'status' => $m->status,
            'daily_budget' => isset($m->daily_budget) ? (float) $m->daily_budget : null,
            'currency' => $m->currency ?? $parent?->currency, 'campaign_id' => $campaignId,
            'started_at' => optional($m->starts_at ?? $m->created_at)->toIso8601String(),
        ];
    }
}
