<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Services;

use App\Domains\Integrations\Models\ExternalAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * UNIFIED-001 — which stores belong to a PROJECT, answered once.
 *
 * ## Why this is not a column
 *
 * `external_accounts` has no `project_id`, and should not grow one: the same row type carries both ad
 * accounts and stores, and where either belongs is a DECISION recorded in `ProjectIntegrationBinding`.
 * This reads the link back from the commerce tables rather than declaring it twice and letting the two
 * disagree.
 *
 * ## COMMERCE-PROJECT-001 — what this comment used to say
 *
 * «What a store belongs to is expressed by where its data was filed — `StoreSyncer::projectIdFor()`
 * decides that on the first sweep and never re-files afterwards.» That was true, and it was the
 * defect: the first sweep decided by taking the tenant's OLDEST project, which for an agency is
 * another client's funnel, and «never re-files afterwards» is what made the accident permanent.
 *
 * A store is now assigned the way an ad account is — explicitly, through the same binding — and
 * `StoreSyncer` refuses one nobody assigned rather than choosing for them. The read below is
 * unchanged; only the sentence describing where the answer comes from was wrong.
 *
 * ## The bug this exists to close
 *
 * The funnel used to take every store in the TENANT. A tenant running two clients out of two projects
 * saw, on either project's funnel, the other client's store counted in `coverage.stores` and named by
 * `stores_without_cart_data` — a store name crossing a project boundary, and a cart-completeness verdict
 * decided by a shop the reader has nothing to do with. Orders were project-scoped the whole time, so the
 * figures were right and the provenance around them was wrong, which is the harder kind to notice.
 */
final class ProjectStores
{
    /** Every commerce table that files rows under a project, and therefore evidences the link. */
    private const COMMERCE_TABLES = [
        'commerce_products',
        'commerce_orders',
        'commerce_customers',
        'commerce_abandoned_carts',
    ];

    /**
     * The stores that have filed data under this project.
     *
     * @return Collection<int, ExternalAccount>
     */
    public function forProject(string $tenantId, string $projectId): Collection
    {
        $ids = $this->accountIdsByProject([$projectId])[$projectId] ?? [];

        if ($ids === []) {
            return collect();
        }

        return ExternalAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('account_type', 'store')
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'provider', 'currency', 'last_synced_at']);
    }

    /**
     * Stores connected to the tenant that have filed nothing anywhere yet.
     *
     * Reported separately rather than folded in, because «no store is connected» and «a store is
     * connected and has not been read yet» are different sentences, and the funnel says the wrong one
     * if it cannot tell them apart. Such a store belongs to no project because nobody has assigned it
     * to one yet — which is a decision waiting to be made, not an answer waiting to be computed.
     *
     * @return Collection<int, ExternalAccount>
     */
    public function unsynced(string $tenantId): Collection
    {
        $filed = $this->filedAccountIds();

        return ExternalAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('account_type', 'store')
            ->when($filed !== [], fn ($q) => $q->whereNotIn('id', $filed))
            ->get(['id', 'name', 'provider', 'currency', 'last_synced_at']);
    }

    /**
     * Bulk: project id => the store ids that filed under it. One query per commerce table, never per
     * project — the client list page asks this for a whole page of clients at once.
     *
     * @param  list<string>  $projectIds
     * @return array<string, list<string>>
     */
    public function accountIdsByProject(array $projectIds): array
    {
        $projectIds = array_values(array_unique(array_filter($projectIds)));

        if ($projectIds === []) {
            return [];
        }

        $out = [];

        foreach (self::COMMERCE_TABLES as $table) {
            $rows = DB::table($table)
                ->whereIn('project_id', $projectIds)
                ->select('project_id', 'external_account_id')
                ->distinct()
                ->get();

            foreach ($rows as $row) {
                $out[(string) $row->project_id][(string) $row->external_account_id] = true;
            }
        }

        return array_map(static fn (array $set): array => array_keys($set), $out);
    }

    /** @return list<string> every store id that has filed data under any project. */
    private function filedAccountIds(): array
    {
        $ids = [];

        foreach (self::COMMERCE_TABLES as $table) {
            foreach (DB::table($table)->distinct()->pluck('external_account_id') as $id) {
                $ids[(string) $id] = true;
            }
        }

        return array_keys($ids);
    }
}
