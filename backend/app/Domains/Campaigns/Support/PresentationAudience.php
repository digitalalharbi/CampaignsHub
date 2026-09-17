<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Support;

/**
 * Who a creative is being presented to, for this request — CLIENT unless an operator surface says otherwise.
 *
 * Fail-closed on purpose: shared links, live reports, snapshots, exports and emails all build previews
 * through the same presenter, and none of them sets this. Only the operator's own content surfaces
 * switch it on, so a sentence meant for the person who can fix a connection («the ad account refuses
 * access») never reaches a client's payload by default.
 */
final class PresentationAudience
{
    private bool $operator = false;

    public function forOperator(): void
    {
        $this->operator = true;
    }

    public function isOperator(): bool
    {
        return $this->operator;
    }
}
