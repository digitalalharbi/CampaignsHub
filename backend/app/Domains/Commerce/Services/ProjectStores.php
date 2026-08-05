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
 * `external_accounts` has no `project_id`, and should not grow one: an ad account is routinely shared
 * across a tenant's projects, and the same row type carries both. What a store belongs to is expressed
 * by where its data was filed — {@see StoreSyncer::projectIdFor()} decides that on the first sweep and
 * never re-files afterwards — so the project link is read back from the commerce tables rather than
 * declared twice and allowed to disagree.
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
     * if it cannot tell them apart. Which project such a store will land in is not knowable until the
     * first sweep files it, so it belongs to none of them until then.
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
