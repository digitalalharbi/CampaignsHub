<?php

declare(strict_types=1);

namespace App\Domains\Billing\Support;

use RuntimeException;

/** Thrown when something tries to rewrite the figures of an issued invoice (INV-IMMUTABLE-001). */
final class ImmutableInvoice extends RuntimeException {}
