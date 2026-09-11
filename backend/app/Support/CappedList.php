<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A list the server bounds, and the count that keeps the bound honest.
 *
 * Roughly a dozen endpoints across this product answer with `->limit(N)->get()` and say nothing
 * about it. The direction is almost always the safe one — newest first, so the rows a reader most
 * likely wants are the rows they get — but an invisible ceiling is indistinguishable from the whole
 * truth. A workspace with two hundred security events saw fifty and had every reason to read that as
 * its entire log; an agency with a hundred and forty invitations saw a hundred.
 *
 * That failure has no symptom. The request is 200, the shape is right, and the count is under the
 * cap the code itself asked for — which is exactly why it survived this long, and why the fix is a
 * COUNT rather than a bigger number.
 *
 * `ClientPortalController::capped()` is the same idea, written first and kept local while it was one
 * controller's problem. This is where it lives now that it is several.
 */
final class CappedList
{
    /**
     * The bounded rows, the real total, and how many the bound kept back.
     *
     * Counted on a CLONE of the query, because `->get()` on a capped builder cannot tell a full page
     * from the end of the list: a list of exactly `$limit` rows and a list of ten thousand look
     * identical once the limit has been applied. Getting that wrong reports «nothing withheld» on
     * precisely the lists that withheld the most.
     *
     * `toBase()` drops the eager loads and the ordering before counting — neither changes how many
     * rows there are, and both cost real time on a query written to render a page.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return array{rows: Collection<int, TModel>, total: int, withheld: int}
     */
    public static function of(Builder $query, int $limit): array
    {
        $total = (clone $query)->toBase()->getCountForPagination();

        /** @var Collection<int, TModel> $rows */
        $rows = $query->limit($limit)->get();

        return ['rows' => $rows, 'total' => $total, 'withheld' => max(0, $total - $rows->count())];
    }

    /**
     * The same contract for a plain query builder.
     *
     * A few of these lists are joins written against `DB::table(...)` because they read across two
     * tables and never need models. They are no less capped and no less read, so they get the same
     * answer rather than an exception to it — an exemption written for the convenience of a helper is
     * how the silent ones came back.
     *
     * @return array{rows: \Illuminate\Support\Collection<int, \stdClass>, total: int, withheld: int}
     */
    public static function ofQuery(QueryBuilder $query, int $limit, array $columns = ['*']): array
    {
        $total = (clone $query)->getCountForPagination();
        $rows = $query->limit($limit)->get($columns);

        return ['rows' => $rows, 'total' => $total, 'withheld' => max(0, $total - $rows->count())];
    }
}
