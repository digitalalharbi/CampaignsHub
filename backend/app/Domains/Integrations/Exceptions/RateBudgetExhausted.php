<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Exceptions;

use RuntimeException;

/** INTEG-SYNC-ENGINE-001 — the request was not sent: the budget for this provider or connection is spent for this window. */
final class RateBudgetExhausted extends RuntimeException {}
