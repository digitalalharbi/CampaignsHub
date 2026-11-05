<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Contracts;

use App\Domains\Integrations\Models\ExternalAccount;

/**
 * INTEG-CAPABILITY-CONTRACT-001 — a provider that reads forward from a stored position.
 *
 * Nothing implements this today, and that is the finding it records: every provider re-reads a window.
 * The pagination cursors in the connectors end with the page they belong to and none is stored between
 * runs. Declaring `IncrementalCursor` requires implementing this, so the claim cannot be made idly.
 */
interface ReadsIncrementally
{
    /** The position the next read starts from, or null when nothing has been read yet. */
    public function incrementalCursor(ExternalAccount $resource): ?string;
}
