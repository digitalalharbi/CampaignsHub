<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Support;

/**
 * ENTITY-RELEVANCE-ORDERING-001 — the two facts the operational reading is made of, on the server.
 *
 * The rule itself lives in `frontend/src/features/campaigns/campaignRelevance.ts`, and it stays
 * there: it is read at every rung of the hierarchy by listings that already hold their rows.
 *
 * What could not stay there is the DEFINITION, the moment a paginated listing needs the same order.
 * The content library is sorted and paged by the database, so ordering one page in the browser would
 * reorder that page and misstate the listing — the order has to be expressed in SQL, and a SQL order
 * needs the same two constants the TypeScript rule uses.
 *
 * Two copies of a definition is what `campaignRelevance`'s own docblock warns against, so this is not
 * a second answer: it is the same two values, and `relevanceDefinition.test.ts` reads THIS FILE and
 * fails when the lists drift apart. A constant that exists twice and is checked once is a constant
 * that exists once.
 */
final class Relevance
{
    /**
     * Statuses that mean the thing is FINISHED WITH — halted, done, filed away.
     *
     * `draft` and `pending` are deliberately absent, and that absence was bought with a real defect
     * on the campaigns workspace: a campaign is created as `draft`, so filing draft under «stopped»
     * made the campaign an operator had just created disappear from the list they created it in. A
     * draft has not stopped; it has not started.
     */
    public const NOT_RUNNING = ['paused', 'completed', 'archived'];

    /**
     * How far behind the window's end a last figure may be and still count as serving.
     *
     * Reporting lags: a platform's figures for yesterday routinely arrive today and sometimes the day
     * after. A creative whose most recent figure is two days old is running, and calling it idle
     * sends an operator to fix something that works. Three days is where silence stops being lag.
     */
    public const SERVING_WITHIN_DAYS = 3;
}
