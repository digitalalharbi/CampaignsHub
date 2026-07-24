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
 */
final class ImportExternalCampaigns
{
    /** @return int number of external campaigns imported/updated */
    public function execute(ExternalAccount $account, SyncResult $result): int
    {
        if (! $result->success) {
            return 0;
        }

        $count = 0;

        DB::transaction(function () use ($account, $result, &$count): void {
            foreach ($result->records as $row) {
                $externalId = (string) ($row['id'] ?? $row['external_id'] ?? '');
                if ($externalId === '') {
                    continue;
                }

                ExternalCampaign::updateOrCreate(
                    [
                        'external_account_id' => $account->id,
                        'external_id' => $externalId,
                    ],
                    [
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
                    ],
                );

                $count++;
            }
        });

        return $count;
    }
}
