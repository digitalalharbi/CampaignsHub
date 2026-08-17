<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Actions;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CampaignObjectiveResolver;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\ValueObjects\SyncResult;
use App\Domains\Metrics\Services\ReportingCurrency;
use Illuminate\Support\Facades\DB;

/**
 * The single seam between the connector layer and stored external campaigns. Given a connector's
 * {@see SyncResult} (rows describing platform campaigns), it idempotently upserts an
 * {@see ExternalCampaign} per row, keyed by (external_account_id, external_id).
 *
 * Existing links (`unified_campaign_id`) are preserved across re-imports — only platform-owned fields
 * are refreshed. Runs under the active tenant + project scopes, so imported rows inherit them.
 *
 * ## Running it without a request (STRUCT-001)
 *
 * The scheduled sweep has no project context to inherit, so it passes one in. That is not merely
 * convenience: `external_campaigns` is unique on `(external_account_id, external_id)` across ALL
 * projects, while the project scope hides rows belonging to another project — so a scoped
 * `updateOrCreate` for an account already imported elsewhere finds nothing, tries to insert, and hits
 * the unique index. Stating the project explicitly and dropping the scopes is what makes the
 * scheduled path idempotent in the same way the wizard's path always was.
 */
final class ImportExternalCampaigns
{
    /**
     * @param  string|null  $projectId  file rows under this project explicitly (queue workers have no
     *                                  request to inherit one from); null keeps the request-scoped behaviour
     * @return int number of external campaigns imported/updated
     */
    public function execute(ExternalAccount $account, SyncResult $result, ?string $projectId = null): int
    {
        if (! $result->success) {
            return 0;
        }

        $count = 0;
        $touched = [];

        DB::transaction(function () use ($account, $result, $projectId, &$count, &$touched): void {
            foreach ($result->records as $row) {
                $externalId = (string) ($row['id'] ?? $row['external_id'] ?? '');
                if ($externalId === '') {
                    continue;
                }

                $query = $projectId === null
                    ? ExternalCampaign::query()
                    : ExternalCampaign::withoutGlobalScopes();

                $campaign = $query->firstOrNew([
                    'external_account_id' => $account->id,
                    'external_id' => $externalId,
                ]);

                /*
                 * The project is settled once, when the row is first created.
                 *
                 * Writing it on every import would MOVE a campaign that somebody has already bound to
                 * a different project — taking its unified-campaign link, its metrics and its reports
                 * with it — every time the sweep happened to resolve a different project for the
                 * account. Where a campaign lives is a decision, not a synced field.
                 */
                $isNew = ! $campaign->exists;

                if ($isNew && $projectId !== null) {
                    $campaign->tenant_id = $account->tenant_id;
                    $campaign->project_id = $projectId;
                }

                $campaign->fill([
                    'client_workspace_id' => $account->client_workspace_id,
                    'provider' => $account->provider,
                    'name' => (string) ($row['name'] ?? $externalId),
                    'status' => CampaignStatus::fromProvider($row['status'] ?? null)->value,
                    'objective' => $row['objective'] ?? null,
                    'daily_budget' => $row['daily_budget'] ?? null,
                    'lifetime_budget' => $row['lifetime_budget'] ?? null,
                    'currency' => $row['currency'] ?? $account->currency,
                    'raw' => $row,
                    'last_synced_at' => now(),
                ])->save();

                /*
                 * CAMPAIGNS-VISIBLE-001 — a synced campaign becomes a campaign somebody can see.
                 *
                 * The Campaigns page lists `unified_campaigns`. Nothing in the sync path had ever
                 * created one: `unified_campaign_id` was only ever set by hand, by a request
                 * conversion, or by the demo seeder. So a customer completed a real first sync,
                 * `external_campaigns` filled up correctly, every metric attached correctly — and
                 * /app/campaigns was empty, with no error and nothing to press.
                 *
                 * Adopted ONE unified campaign per platform campaign, not one shared across
                 * platforms. Grouping «the same campaign on Meta and on Snapchat» is a judgement
                 * about the customer's intent that only the customer can make; inventing it here
                 * would merge two budgets under one name on the strength of a matching string. One
                 * each is the honest default, and merging afterwards is a decision they can take.
                 *
                 * Only on FIRST import, never on a re-import. `unified_campaign_id === null` would
                 * have been the obvious condition and it is wrong: `CampaignLinker::unlink()` sets it
                 * to null deliberately, so adopting on null means the next sweep silently undoes a
                 * person's decision to unlink — and the suggestions list, which is built from
                 * unlinked externals, would never have anything in it again.
                 *
                 * A row is adopted once, when it first arrives. What happens to it after that belongs
                 * to whoever is looking at it.
                 */
                if ($isNew && $campaign->unified_campaign_id === null) {
                    $campaign->unified_campaign_id = $this->adopt($campaign, $account)->getKey();
                    $campaign->save();
                }

                $touched[] = (string) $campaign->unified_campaign_id;

                $count++;
            }
        });

        $this->refreshObjectives($touched);

        return $count;
    }

    /**
     * The visible campaign a synced platform campaign belongs to, created on first sight.
     *
     * Everything here is derived from what the platform actually reported. `status` is the platform's
     * own — a paused campaign that appeared as `draft` would be a claim about the customer's
     * intention rather than a fact about their account — and the budget carries the platform's
     * currency rather than being converted, because conversion belongs to the reporting layer where
     * the rate and its date are recorded beside the figure.
     */
    private function adopt(ExternalCampaign $campaign, ExternalAccount $account): UnifiedCampaign
    {
        /*
         * Keyed on (project, name), because the database already says that is what a campaign IS.
         *
         * `unified_campaigns` is unique on `(project_id, name)`. Creating one per platform campaign
         * unconditionally therefore breaks the moment two ad accounts in one project run a campaign
         * of the same name — an agency with two Snapchat accounts both running «Ramadan Sale» is not
         * an exotic case, and the second account's sync would die on a 23505 with nothing on screen
         * to explain it.
         *
         * Reusing the row is also the truer reading: within ONE project, two platform campaigns
         * sharing a name are the same business campaign run in two places, which is exactly what a
         * unified campaign is for. Across projects nothing is shared, because the key includes the
         * project.
         */
        return UnifiedCampaign::withoutGlobalScopes()->firstOrCreate([
            'project_id' => $campaign->project_id,
            'name' => $campaign->name,
        ], [
            'tenant_id' => $campaign->tenant_id ?? $account->tenant_id,
            'client_workspace_id' => $campaign->client_workspace_id,
            /*
             * `other` when the platform reported no objective — never a guess at one.
             *
             * The column is NOT NULL and `CampaignObjectiveResolver` runs immediately after this
             * import, so the value here is a starting point rather than a verdict: it re-derives from
             * the platform's own field and refuses to touch an objective a person has set by hand.
             * `other` is the resolver's own «not classified» value, and objective-based reporting
             * already keeps such spend out of a cost-per-order it cannot honestly attribute.
             */
            'objective' => $campaign->objective ?? CampaignObjective::Other->value,
            'status' => $campaign->status,
            'total_budget' => $campaign->lifetime_budget,
            /*
             * The PLATFORM's currency, kept as the platform stated it.
             *
             * Not converted here: conversion belongs to the reporting layer, where the rate, its date
             * and its source are recorded beside the figure. The column is NOT NULL, so an account
             * whose provider reported no currency falls back to the reporting default rather than
             * blocking the import — a budget with no currency is not a number anybody can read.
             */
            'budget_currency' => $campaign->currency ?? $account->currency ?? ReportingCurrency::DEFAULT,
            'platforms' => [$campaign->provider],
            /*
             * Marked as adopted rather than authored.
             *
             * A campaign the product created from a sync and one somebody typed are different things
             * — the first may be renamed on the platform tomorrow — and a screen that offers to
             * «edit» them identically should at least be able to tell them apart.
             */
            'meta' => ['adopted_from' => 'sync', 'provider' => $campaign->provider],
        ]);
    }

    /**
     * Re-derive the objective of every unified campaign this import touched (REPORT-OBJECTIVE-002).
     *
     * Outside the transaction on purpose: adopting an objective writes an audit row, and a sweep that
     * rolled back for an unrelated reason should not take the trail of what it decided with it.
     *
     * A platform can change a campaign's objective after it has been running — an advertiser
     * switching a campaign from traffic to sales mid-month is ordinary — and until this ran, the
     * classification was whatever it had been at first import. The resolver refuses to touch a
     * `manual` objective, so a person's correction survives every sweep.
     *
     * @param  list<string>  $unifiedCampaignIds
     */
    private function refreshObjectives(array $unifiedCampaignIds): void
    {
        if ($unifiedCampaignIds === []) {
            return;
        }

        $resolver = app(CampaignObjectiveResolver::class);

        UnifiedCampaign::withoutGlobalScopes()
            ->whereIn('id', array_unique($unifiedCampaignIds))
            ->each(fn (UnifiedCampaign $campaign) => $resolver->sync($campaign));
    }
}
