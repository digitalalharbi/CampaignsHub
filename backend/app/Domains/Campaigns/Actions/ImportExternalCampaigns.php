<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Actions;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CampaignObjectiveResolver;
use App\Domains\Campaigns\Services\PlatformObjectiveMap;
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
                 * Adopted when it has never been adopted AND never been unlinked — CAMPAIGNS-ADOPT-001.
                 *
                 * `unified_campaign_id === null` alone is the obvious condition and it is wrong:
                 * `CampaignLinker::unlink()` produces exactly that, so adopting on it would undo a
                 * person's decision on the next sweep, and the suggestions list — built from unlinked
                 * externals — would never have anything in it again.
                 *
                 * The first fix for that was `$isNew`, and it had a worse failure. A campaign
                 * discovered BEFORE adoption existed is never new again, so it is never adopted: on
                 * the live Snapchat account, 89 campaigns and 1,056 stored metrics with an empty
                 * Campaigns page and nothing to press. `unlinked_at` is the record the condition was
                 * missing — it says «somebody detached this on purpose», which is the only thing
                 * that had to be protected.
                 *
                 * A row is still adopted once. What happens to it after that belongs to whoever is
                 * looking at it, and now it sticks.
                 */
                if ($campaign->unified_campaign_id === null && $campaign->unlinked_at === null) {
                    $campaign->unified_campaign_id = $this->adopt($campaign, $account)->getKey();
                    $campaign->save();
                }

                /*
                 * Only a real link is worth re-deriving an objective for.
                 *
                 * Pushing unconditionally put an empty string in here for every campaign somebody had
                 * deliberately unlinked, and `whereIn` then handed Postgres `''` as a uuid — a 22P02
                 * that killed the whole import over a campaign that was correctly detached.
                 */
                if ($campaign->unified_campaign_id !== null) {
                    $touched[] = (string) $campaign->unified_campaign_id;
                }

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
         * ONE visible campaign per platform campaign — never a merge across platforms.
         *
         * Grouping «the same campaign on Meta and on Snapchat» is a judgement about the customer's
         * intent that only the customer can make. Matching on name would merge two budgets under one
         * heading on the strength of a matching string, which is worse than leaving them apart: the
         * separation is visible and fixable, a wrong merge is neither.
         *
         * Identity is therefore the EXTERNAL campaign — tenant, project, provider, account and the
         * provider's own campaign id — and it is expressed as the link on the external row, which is
         * what makes a repeated sync idempotent without any matching at all.
         */
        return UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $campaign->tenant_id ?? $account->tenant_id,
            'project_id' => $campaign->project_id,
            'client_workspace_id' => $campaign->client_workspace_id,
            'name' => $this->availableName($campaign),
            /*
             * A CANONICAL objective, or `other` — never the platform's own word.
             *
             * See `seedObjective()`. This line used to be `$campaign->objective ?? other`, which
             * wrote the provider's raw string into the column that is supposed to hold a
             * {@see CampaignObjective} value.
             */
            'objective' => $this->seedObjective($campaign),
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
             * Marked as adopted rather than authored, and carrying what it was adopted FROM.
             *
             * A campaign the product created from a sync and one somebody typed are different things
             * — the first may be renamed on the platform tomorrow — and a screen that offers to
             * «edit» them identically should at least be able to tell them apart. The provider and
             * external id make the origin traceable without another join.
             */
            'meta' => [
                'adopted_from' => 'sync',
                'provider' => $campaign->provider,
                'external_account_id' => (string) $account->getKey(),
                'external_campaign_id' => (string) $campaign->external_id,
            ],
        ]);
    }

    /**
     * A name this project does not already hold.
     *
     * `unified_campaigns` is unique on `(project_id, name)`, and two ad accounts in one project
     * running a campaign of the same name is ordinary — an agency with two Snapchat accounts both
     * running «Ramadan Sale» would otherwise hit a `23505` and the second account's sync would die
     * with nothing on screen to explain it.
     *
     * Disambiguated by the platform's own campaign id rather than by a counter, so the suffix means
     * something to whoever reads it and is stable across re-imports. Renaming it afterwards is a
     * decision the customer can take; guessing that the two are the same campaign is not one we may
     * take for them.
     */
    /**
     * OBJECTIVE-NORMALIZATION-002 — the canonical column may only ever hold a canonical value.
     *
     * This line used to be `$campaign->objective ?? CampaignObjective::Other->value`: the platform's
     * OWN string, written straight into the column that is supposed to hold a {@see CampaignObjective}
     * case. The comment beside it argued the value was only a starting point, because
     * `CampaignObjectiveResolver` runs immediately afterwards and re-derives it.
     *
     * That argument holds only while the resolver can classify. It bails when the platform's word is
     * not in `PlatformObjectiveMap` — which is exactly the case where the raw string got written —
     * so the unrecognised value was not a starting point at all. It was the final state.
     *
     * Production shows what that costs. Every campaign on this account carries `SALES`, Snapchat's
     * own word, in the canonical column; `CampaignObjective::tryFrom('SALES')` fails; every creative
     * therefore resolves to `ObjectiveFamily::Unknown`, and objective-aware KPI selection has been
     * silently off for the whole account.
     *
     * The raw value is not lost. `objective_platform_value` is where the platform's own word belongs,
     * and `CampaignObjectiveResolver` writes it there whether or not it could classify — including,
     * deliberately, when it could not.
     */
    private function seedObjective(ExternalCampaign $campaign): string
    {
        // `app()` rather than a constructor, matching how this action already reaches
        // `CampaignObjectiveResolver` a few lines below.
        $resolved = app(PlatformObjectiveMap::class)->resolve(
            (string) $campaign->provider,
            $campaign->objective,
        );

        return ($resolved ?? CampaignObjective::Other)->value;
    }

    private function availableName(ExternalCampaign $campaign): string
    {
        $name = (string) $campaign->name;

        $taken = fn (string $candidate): bool => UnifiedCampaign::withoutGlobalScopes()
            ->where('project_id', $campaign->project_id)
            ->where('name', $candidate)
            ->exists();

        if (! $taken($name)) {
            return $name;
        }

        $qualified = mb_substr($name.' · '.$campaign->external_id, 0, 160);

        return $taken($qualified)
            ? mb_substr($name.' · '.$campaign->external_id.' · '.$campaign->getKey(), 0, 160)
            : $qualified;
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
