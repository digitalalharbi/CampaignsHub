<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services\Attention;

/**
 * The ONE action each attention block may carry, and who is allowed to read it.
 *
 * ## Why the audience lives in the catalogue and never on the item
 *
 * A stored item could say `audience: client` about anything — an old snapshot, a hand-edited row, a
 * future detector that forgot. So the client filter never believes the item: it asks this list
 * whether the ACTION is something a client may be told. An action that is not listed here is not
 * client-safe, which makes a new action fail closed until somebody decides otherwise.
 *
 * ## What «operator-internal» means
 *
 * The client report rule: never expose internal buying tactics or methodology. Moving budget between
 * platforms or tactics, bid strategy and retargeting mechanics are how the agency does the work, not
 * what the client's money achieved. They are real recommendations and the operator should see them —
 * they reach a client only when the operator approves that specific item.
 */
final class AttentionActions
{
    /** action => client-safe */
    public const CATALOGUE = [
        // Client-safe: each names something the client can see and discuss, with no buying mechanics.
        'refresh_creative' => true,
        'review_landing_page' => true,
        'review_cost_drivers' => true,
        'check_conversion_tracking' => true,
        'keep_what_works' => true,
        // Operator-internal: buying tactics.
        'shift_budget' => false,
        'review_bidding' => false,
        'review_retargeting' => false,
    ];

    public static function isKnown(string $action): bool
    {
        return array_key_exists($action, self::CATALOGUE);
    }

    public static function isClientSafe(string $action): bool
    {
        return self::CATALOGUE[$action] ?? false;
    }
}
