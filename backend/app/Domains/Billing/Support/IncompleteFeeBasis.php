<?php

declare(strict_types=1);

namespace App\Domains\Billing\Support;

use RuntimeException;

/**
 * A percentage fee cannot be drafted because the spend it is a percentage OF is not whole — some of the
 * period's money is withheld, or no rate exists to put it in the invoice currency. Missing is not zero: an
 * invoice for a fraction of the real fee is worse than no draft.
 */
final class IncompleteFeeBasis extends RuntimeException {}
