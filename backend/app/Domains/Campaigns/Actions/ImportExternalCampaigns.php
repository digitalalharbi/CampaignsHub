<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Actions;

use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\ValueObjects\SyncResult;
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

        DB::transaction(function () use ($account, $result, $projectId, &$count): void {
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
                if (! $campaign->exists && $projectId !== null) {
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

                $count++;
            }
        });

        return $count;
    }
}
