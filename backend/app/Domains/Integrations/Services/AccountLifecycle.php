<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\ExternalAccount;

/**
 * COMMAND-CENTER §7 — discovered, enabled and assigned are three different things.
 *
 * ## The conflation this separates, and the bill it produced
 *
 * One real Snapchat authorisation returned **309 ad accounts**. The product had one word for all of
 * them — «متصل» — and that single word was asked to mean three unrelated things at once:
 *
 *  - the provider told us this exists (we did not choose it, and it may not even be the customer's);
 *  - the customer has decided this is one of theirs and wants it in CampaignsHub;
 *  - some project owns it, so its spend appears in that project's reporting and its numbers are
 *    somebody's numbers.
 *
 * Collapsing those is what makes an integrations page unreadable at 309 rows, and it is also how an
 * account nobody chose ends up being counted, billed and reported on. The states are named here so
 * every surface asks the same question and gets the same answer.
 *
 * ## The four states
 *
 *  - `DISCOVERED` — the provider returned it. A fact about the provider, not a decision. Nothing
 *    syncs, nothing is counted, nothing is billed.
 *  - `ENABLED` — the customer said «this one is mine». Still nothing syncs: enabling is an act of
 *    curation, not of connection. It exists so 309 rows can be reduced to the four that matter
 *    without pretending the other 305 were never there.
 *  - `EXCLUDED` — the customer said «this one is not mine, stop showing it to me». Kept rather than
 *    deleted, because the next discovery would hand it straight back and the answer would be lost.
 *  - `ASSIGNED` — an ACTIVE binding names a project. **This is the only state in which data moves.**
 *
 * ## Assigned is DERIVED and never stored
 *
 * The other three are decisions, so they are recorded. `ASSIGNED` is not a decision, it is a
 * consequence of `ProjectIntegrationBinding` — the one ownership record. A stored copy would be a
 * second opinion about who owns an account, and the whole of RUNTIME-100 exists because this product
 * previously had several. Ask the binding, always.
 *
 * ## Precedence
 *
 * Assignment outranks curation. An account somebody bound to a project is `ASSIGNED` even if it was
 * never explicitly enabled — binding it is a stronger statement than enabling it, and reporting such
 * an account as merely «مُفعّل» would understate what is actually happening to its data. Excluding
 * an assigned account is refused at the controller rather than silently losing to this rule.
 */
final class AccountLifecycle
{
    /** The provider returned it. Nobody has decided anything. */
    public const DISCOVERED = 'discovered';

    /** The customer claims it. Still not syncing — that needs a project. */
    public const ENABLED = 'enabled';

    /** The customer disowned it. Hidden by default, never deleted. */
    public const EXCLUDED = 'excluded';

    /** A project owns it. The only state in which data moves. */
    public const ASSIGNED = 'assigned';

    /** The states a person may set directly. `ASSIGNED` is absent on purpose — it is earned. */
    public const SETTABLE = [self::DISCOVERED, self::ENABLED, self::EXCLUDED];

    public function __construct(private readonly AccountAssignment $assignment) {}

    /**
     * The one state of one account.
     *
     * `$assigned` may be passed when the caller has already resolved assignment for a whole page of
     * accounts — 309 rows is 309 binding lookups otherwise, and the answer would be identical.
     */
    public function stateFor(ExternalAccount $account, ?bool $assigned = null): string
    {
        $isAssigned = $assigned ?? $this->assignment->isActivelyAssigned($account);

        if ($isAssigned) {
            return self::ASSIGNED;
        }

        return match ($account->management_state) {
            self::ENABLED => self::ENABLED,
            self::EXCLUDED => self::EXCLUDED,
            default => self::DISCOVERED,
        };
    }

    /**
     * Whether a state means the customer has taken an interest — the Connection Center's default view.
     *
     * `DISCOVERED` is excluded from this on purpose: with 309 rows, showing everything by default is
     * the same as showing nothing.
     */
    public function isCurated(string $state): bool
    {
        return $state === self::ENABLED || $state === self::ASSIGNED;
    }

    /**
     * How many of each state, for a page that has to say «4 of 309» rather than list 309.
     *
     * @param  list<string>  $states
     * @return array<string, int>
     */
    public function summarise(array $states): array
    {
        $counts = [self::DISCOVERED => 0, self::ENABLED => 0, self::EXCLUDED => 0, self::ASSIGNED => 0];

        foreach ($states as $state) {
            if (isset($counts[$state])) {
                $counts[$state]++;
            }
        }

        return $counts;
    }
}
