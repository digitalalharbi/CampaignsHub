<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use Illuminate\Support\Facades\DB;

/**
 * LIVE-LINK-ACCOUNT-IDENTITY-001 — an ad account is the provider's account, not the row that happens to hold it.
 *
 * A key entered again opens a new connection and a new `external_accounts` row for the SAME provider ad account
 * (same tenant, provider, type and external id), binds it to the project and deactivates the old row's binding. Anything
 * that stored the old row's id as «this account» — a live link's ceiling above all — then names a row that
 * `BoundAccountVisibility` hides, while the account's own content sits under a row the ceiling never named.
 *
 * What this adds is deliberately narrow: for the accounts a grant already names, the OTHER rows of the same provider
 * account that are ACTIVELY bound to the very project the grant belongs to. Never a different ad account, never a row
 * bound to another project, never an inactive one — so nothing is authorised that the operator had not authorised.
 */
final class ProviderAccountIdentity
{
    /**
     * @param  list<string>  $accountIds  the account row ids a grant names
     * @return list<string> the same ids, then the sibling rows of those accounts that the project actively binds
     */
    public static function followed(array $accountIds, string $projectId): array
    {
        $accountIds = array_values(array_unique(array_filter($accountIds, static fn ($id): bool => is_string($id) && $id !== '')));

        if ($accountIds === [] || $projectId === '') {
            return $accountIds;
        }

        $siblings = DB::table('external_accounts as sib')
            ->join('external_accounts as named', function ($join): void {
                $join->on('named.tenant_id', '=', 'sib.tenant_id')
                    ->on('named.provider', '=', 'sib.provider')
                    ->on('named.account_type', '=', 'sib.account_type')
                    ->on('named.external_id', '=', 'sib.external_id');
            })
            ->whereIn('named.id', $accountIds)
            ->whereExists(fn ($b) => $b->selectRaw('1')
                ->from('project_integration_bindings as pb')
                ->whereColumn('pb.external_account_id', 'sib.id')
                ->where('pb.project_id', $projectId)
                ->where('pb.is_active', true))
            ->distinct()
            ->pluck('sib.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return array_values(array_unique([...$accountIds, ...$siblings]));
    }
}
