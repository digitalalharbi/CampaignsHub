<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Models\DailyMetric;

/**
 * REPORT-SCOPE-HIERARCHY-001 — Account → Campaign → Ad, verified against the project, stated once.
 *
 * Live links and saved reports both ask this class, so «which campaigns can this account's report
 * name» has one answer. Every failure is a 422, never a quiet drop: dropping a stray id would turn
 * «Razzah plus something mistyped» into a narrower report nobody asked for, and an EMPTY account
 * list is refused rather than read as «every account the project happens to bind».
 */
final class ReportScopeHierarchy
{
    /**
     * Campaign ids behind each of the given accounts, as this project shows them.
     *
     * @param  list<string>  $accountIds
     * @return array<string, list<string>> account id → unified campaign ids
     */
    public static function campaignsByAccount(string $project, array $accountIds): array
    {
        abort_if($accountIds === [], 422, 'Choose at least one ad account.');

        $byAccount = [];

        $rows = DailyMetric::query()
            ->where('project_id', $project)
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'daily_metrics'))
            ->whereIn('external_account_id', $accountIds)
            ->whereNotNull('unified_campaign_id')
            ->distinct()
            ->get(['external_account_id', 'unified_campaign_id']);
        foreach ($rows as $row) {
            $byAccount[(string) $row->external_account_id][] = (string) $row->unified_campaign_id;
        }

        // Campaign structure counts too: a bound account with campaigns and no figures yet is still chosen.
        $structure = ExternalCampaign::query()
            ->where('project_id', $project)
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'external_campaigns'))
            ->whereIn('external_account_id', $accountIds)
            ->whereNotNull('unified_campaign_id')
            ->distinct()
            ->toBase()
            ->get(['external_account_id', 'unified_campaign_id']);
        foreach ($structure as $row) {
            $byAccount[(string) $row->external_account_id][] = (string) $row->unified_campaign_id;
        }

        /*
         * REPORT-BOUND-ACCOUNTS-001 — an account the project BINDS is an account of the project.
         *
         * Whether campaigns or figures have arrived yet is a fact about the sync, not about
         * ownership. A freshly bound Google or Meta account has neither, and refusing it here made
         * the report unable to name it. It stays chosen, with no campaigns behind it yet.
         */
        $bound = array_flip(BoundAccountVisibility::activeAccountIds($project));
        foreach ($accountIds as $account) {
            if (! isset($byAccount[$account]) && isset($bound[$account])) {
                $byAccount[$account] = [];
            }
            abort_unless(isset($byAccount[$account]), 422, 'An ad account is not part of this project.');
            $byAccount[$account] = array_values(array_unique($byAccount[$account]));
        }

        return $byAccount;
    }

    /**
     * Whether every ad belongs to one of the campaigns.
     *
     * @param  list<string>  $adIds
     * @param  list<string>  $campaignIds
     */
    public static function adsBelong(string $project, array $adIds, array $campaignIds): bool
    {
        if ($adIds === []) {
            return true;
        }

        $valid = ExternalAd::query()
            ->where('project_id', $project)
            ->whereIn('id', $adIds)
            ->whereIn('unified_campaign_id', $campaignIds)
            ->pluck('id')
            ->map(strval(...))
            ->all();

        return array_diff($adIds, $valid) === [];
    }

    /**
     * The flat form a saved report's scope takes: explicit accounts, campaigns inside them, ads inside those.
     *
     * An empty campaign list stays empty — «all of these accounts' campaigns», which the engine bounds
     * by the account axis — but a named campaign must sit under a selected account.
     *
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed> the same scope, verified
     */
    public static function verifyFlat(string $project, array $scope): array
    {
        // SECURITY CLUSTER 5 — the project axis can only name the project the report is being made in.
        $projects = array_values(array_unique(array_filter((array) ($scope['project_ids'] ?? []), 'is_string')));
        abort_unless(array_diff($projects, [$project]) === [], 422, 'A report scope can only name its own project.');

        $accounts = array_values(array_unique(array_filter((array) ($scope['account_ids'] ?? []), 'is_string')));
        $byAccount = self::campaignsByAccount($project, $accounts);
        $within = array_values(array_unique(array_merge(...array_values($byAccount))));

        $campaigns = array_values(array_unique(array_filter((array) ($scope['campaign_ids'] ?? []), 'is_string')));
        abort_unless(array_diff($campaigns, $within) === [], 422, 'A campaign does not belong to the selected ad accounts.');

        $ads = array_values(array_unique(array_filter((array) ($scope['ad_ids'] ?? []), 'is_string')));
        abort_unless(self::adsBelong($project, $ads, $campaigns === [] ? $within : $campaigns), 422, 'An ad does not belong to the selected campaigns.');

        return ['account_ids' => $accounts, 'campaign_ids' => $campaigns, 'ad_ids' => $ads] + $scope;
    }
}
