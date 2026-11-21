<?php

declare(strict_types=1);

namespace App\Domains\Integrations\ValueObjects;

use App\Domains\Integrations\Models\ExternalAccount;
use Illuminate\Support\Collection;

/** What a discovery left in `external_accounts` for the connection, read back rather than reported. */
final class DiscoveredResources
{
    /** @param Collection<int, ExternalAccount> $resources */
    public function __construct(public readonly Collection $resources) {}

    /** @return list<string> */
    public function accountTypes(): array
    {
        return $this->resources->pluck('account_type')->unique()->values()->all();
    }

    public function anyCarriesAParent(): bool
    {
        return $this->resources->contains(static fn (ExternalAccount $row): bool => $row->parent_external_id !== null);
    }
}
