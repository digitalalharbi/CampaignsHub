<?php

declare(strict_types=1);

namespace App\Domains\Automation\Services;

use App\Domains\Automation\Models\AutomationRule;
use App\Domains\Automation\Support\CampaignAge;
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
    /** @return list<array{id: string, level: string, provider: string, external_account_id: ?string, external_id: string, name: ?string, status: ?string, daily_budget: ?float, budget_source: ?string, currency: ?string, started_at: ?string, campaign_id: ?string}> */
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
            $setBudgets = $this->activeAdSetBudgets($rule, $campaigns->modelKeys());

            return $campaigns->map(fn (ExternalCampaign $c): array => $this->row('campaign', $c, (string) $c->getKey(), null, $setBudgets[(string) $c->getKey()] ?? null))->all();
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

    /**
     * CONS-I-BUDGET — a campaign with no budget of its own may carry it on its ad sets (budget set below the campaign).
     * The daily budget of the campaign is then what its ACTIVE ad sets add up to; with none stated it stays null.
     *
     * @param  list<string>  $campaignIds
     * @return array<string, float>
     */
    private function activeAdSetBudgets(AutomationRule $rule, array $campaignIds): array
    {
        if ($campaignIds === []) {
            return [];
        }
        $out = [];
        foreach (ExternalAdSet::withoutGlobalScopes()->where('project_id', $rule->project_id)->whereIn('external_campaign_id', $campaignIds)
            ->where('status', 'active')->where('daily_budget', '>', 0)->get(['external_campaign_id', 'daily_budget']) as $set) {
            $out[(string) $set->external_campaign_id] = ($out[(string) $set->external_campaign_id] ?? 0.0) + (float) $set->daily_budget;
        }

        return $out;
    }

    private function row(string $level, mixed $m, string $campaignId, ?ExternalCampaign $parent = null, ?float $adSetBudgets = null): array
    {
        $parent ??= $m instanceof ExternalCampaign ? $m : null;
        $own = isset($m->daily_budget) && (float) $m->daily_budget > 0 ? (float) $m->daily_budget : null;
        $budget = $own ?? ($adSetBudgets !== null && $adSetBudgets > 0 ? $adSetBudgets : null);

        return [
            'id' => (string) $m->getKey(), 'level' => $level, 'provider' => AdPlatforms::canonical((string) $m->provider),
            'external_account_id' => $parent?->external_account_id !== null ? (string) $parent->external_account_id : null,
            'external_id' => (string) $m->external_id, 'name' => $m->name, 'status' => $m->status,
            'daily_budget' => $budget,
            // Where the budget was read: the object itself, or the sum of its ad sets (read-only: a change is asked of the ad sets, not the campaign).
            'budget_source' => $budget === null ? null : ($own !== null ? 'self' : 'ad_sets'),
            'currency' => $m->currency ?? $parent?->currency, 'campaign_id' => $campaignId,
            // CONS-I-AGE — the CAMPAIGN's creation date on the platform (an ad set or ad is as old as its campaign); null when unknown, never the first-sync date.
            'started_at' => CampaignAge::createdAt($parent)?->toIso8601String(),
        ];
    }
}
