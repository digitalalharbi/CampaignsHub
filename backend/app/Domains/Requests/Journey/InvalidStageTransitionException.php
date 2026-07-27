<?php

declare(strict_types=1);

namespace App\Domains\Requests\Journey;

use DomainException;

/**
 * Raised when a caller attempts a journey transition the state machine forbids. A domain-level failure —
 * never a silent no-op — so an invalid stage jump (e.g. draft → completed) is always rejected loudly.
 */
final class InvalidStageTransitionException extends DomainException
{
    public function __construct(
        public readonly RequestStage $from,
        public readonly RequestStage $to,
    ) {
        parent::__construct("Cannot transition request from '{$from->value}' to '{$to->value}'.");
    }
}
