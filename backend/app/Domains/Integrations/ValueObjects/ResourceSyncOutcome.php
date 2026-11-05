<?php

declare(strict_types=1);

namespace App\Domains\Integrations\ValueObjects;

/**
 * One sync of one resource, in the words all three families' run ledgers already share.
 *
 * `$status` is a `SyncRunStatus` value; each family's own run row stays the record, and this only
 * saves a caller learning three shapes.
 */
final class ResourceSyncOutcome
{
    public function __construct(
        public readonly string $status,
        public readonly int $records,
        public readonly ?string $error = null,
    ) {}
}
