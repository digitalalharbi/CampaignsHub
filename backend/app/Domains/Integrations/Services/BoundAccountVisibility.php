<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ACCOUNT-SCOPE-ISOLATION-001 — the one rule for which stored rows a project may SHOW.
 *
 * ## The Owner's observation, and the ruling it produced
 *
 * «Campaigns/data from accounts other than the exact selected account being mixed into results.» A
 * project's selected accounts are its ACTIVE rows in `project_integration_bindings`. Every write path
 * honours that set; no reader did — every surface narrowed by tenant and project alone, so a row filed
 * under a project stayed in its totals for ever: after the operator deselected the account, after the
 * account was re-bound to another project, after a sync that predates assignment filed it there.
 *
 * The ruling: a row is visible in a project only while its account is ACTIVELY bound to that project.
 * Deselected history is never deleted — it is legitimate and it reappears the moment the account is
 * re-selected — but it is not this project's figure while the operator has said it is not.
 *
 * ## One rule, one predicate, applied per ROW
 *
 * The predicate is written against the row's OWN `project_id`, not against an ambient project, so it
 * holds in a single-project read and in a multi-project one alike (the client rollups, the alert
 * sweep, the digest). Two readings of it:
 *
 *   - visible: an ACTIVE binding exists for (this row's account, this row's project)
 *   - visible: the account holds NO binding row anywhere — nothing was ever selected or deselected
 *     about it, which is the shape of seeded demo data and of a fixture; it is also the shape of rows
 *     synced before assignment existed, and THOSE are the cleanup's business, not this predicate's,
 *     because hiding them would hide the evidence the cleanup is deciding on
 *   - hidden: everything else — a binding to this project that is now inactive (deselected), an
 *     active binding to a DIFFERENT project (re-bound, and the rows belong to that project's totals)
 *
 * ## Where the account is
 *
 * `daily_metrics`, `entity_daily_metrics`, `external_campaigns` and the commerce tables carry
 * `external_account_id`. Ad sets, ads, creatives and creative metrics carry none and resolve through
 * `external_campaigns`; the second method walks that join. An `entity_daily_metrics` row whose own
 * column is null is resolved through its campaign the same way.
 *
 * `BoundAccountVisibilityTest` holds the rule with two accounts on one connection, and a source guard
 * holds that every reader of those tables asks THIS class rather than restating the predicate.
 */
final class BoundAccountVisibility
{
    /**
     * Whether a row whose account holds NO binding record anywhere is shown.
     *
     * Decided by the Production inventory, not assumed. Such rows are either history filed before
     * assignment existed — legitimate, and hiding them would hide the evidence the cleanup decides on
     * — or a leak with no binding to catch it. `integrations:scope-audit` counts them as `unbound`
     * per project, provider and month; this is the one line to flip once that count has been read,
     * and `BoundAccountVisibilityTest` pins whichever reading is in force.
     */
    public const UNBOUND_ROWS_VISIBLE = true;

    /**
     * Narrow a query on a table that carries `external_account_id` and `project_id` itself.
     *
     * @template T of BuilderContract
     *
     * @param  T  $query
     * @param  string  $table  the alias the columns are read from, e.g. `daily_metrics` or `m`
     * @return T
     */
    public static function apply(BuilderContract $query, string $table): BuilderContract
    {
        return $query->where(function ($q) use ($table): void {
            $account = "{$table}.external_account_id";
            $project = "{$table}.project_id";

            $q->whereExists(fn (Builder $b) => self::activeBinding($b, $account, $project))
                ->when(self::UNBOUND_ROWS_VISIBLE, fn ($w) => $w->orWhereNotExists(fn (Builder $b) => self::anyBinding($b, $account)));
        });
    }

    /**
     * Narrow a query on a table whose account is known only through `external_campaigns`.
     *
     * @template T of BuilderContract
     *
     * @param  T  $query
     * @param  string  $campaignColumn  the column naming `external_campaigns.id`, e.g. `t.external_campaign_id`
     * @param  string  $projectColumn  the row's own project column, e.g. `t.project_id`
     * @return T
     */
    public static function applyThroughCampaign(BuilderContract $query, string $campaignColumn, string $projectColumn): BuilderContract
    {
        return $query->where(function ($q) use ($campaignColumn, $projectColumn): void {
            $account = "(select c.external_account_id from external_campaigns c where c.id = {$campaignColumn})";

            $q->whereExists(fn (Builder $b) => self::activeBinding($b, $account, $projectColumn))
                ->when(self::UNBOUND_ROWS_VISIBLE, fn ($w) => $w->orWhereNotExists(fn (Builder $b) => self::anyBinding($b, $account)))
                /*
                 * A row that hangs off no campaign at all — an estimated demo creative, a row whose
                 * campaign was removed — resolves to no account, and no account is not a deselected
                 * account. It stays visible; the inventory reports it as `none`.
                 */
                ->orWhereRaw("{$campaignColumn} is null");
        });
    }

    /**
     * Narrow a query on `entity_daily_metrics`, whose own account column is nullable and falls back to
     * the campaign the row names.
     *
     * @template T of BuilderContract
     *
     * @param  T  $query
     * @return T
     */
    public static function applyToEntityMetrics(BuilderContract $query, string $table): BuilderContract
    {
        return $query->where(function ($q) use ($table): void {
            $account = "COALESCE({$table}.external_account_id, (select c.external_account_id from external_campaigns c where c.id = {$table}.external_campaign_id))";
            $project = "{$table}.project_id";

            $q->whereExists(fn (Builder $b) => self::activeBinding($b, $account, $project))
                ->when(self::UNBOUND_ROWS_VISIBLE, fn ($w) => $w->orWhereNotExists(fn (Builder $b) => self::anyBinding($b, $account)));
        });
    }

    /** The set of account ids a project may currently show — for callers that hold a list rather than a query. */
    public static function activeAccountIds(string $projectId): array
    {
        return DB::table('project_integration_bindings')
            ->where('project_id', $projectId)
            ->where('is_active', true)
            ->distinct()
            ->pluck('external_account_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    private static function activeBinding(Builder $b, string $accountExpression, string $projectExpression): void
    {
        $b->selectRaw('1')
            ->from('project_integration_bindings as bav')
            ->whereRaw("bav.external_account_id = {$accountExpression}")
            ->whereRaw("bav.project_id = {$projectExpression}")
            ->where('bav.is_active', true);
    }

    private static function anyBinding(Builder $b, string $accountExpression): void
    {
        $b->selectRaw('1')
            ->from('project_integration_bindings as bav')
            ->whereRaw("bav.external_account_id = {$accountExpression}");
    }
}
