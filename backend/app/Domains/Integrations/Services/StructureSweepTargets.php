<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use Illuminate\Support\Collection;

/**
 * STRUCT-001 — the one definition of «which ad accounts a structure sweep covers».
 *
 * The scheduled sweep queues them and the acceptance check watches them, and those two must agree
 * about the set or the check is watching for runs that were never asked for. Extracted rather than
 * copied for exactly that reason: a second copy of this query is a second answer to the question.
 *
 * Connected connections only, ad accounts only, active only, assigned only — the same four
 * conditions the metrics sweep applies, and for the same reasons. Discovery catalogues; assignment
 * is what asks for the data; a revoked connection attempted every pass writes a failure row for ever
 * and buries the one failure that means something.
 */
final class StructureSweepTargets
{
    public function __construct(private readonly AccountAssignment $assignment) {}

    /**
     * @return Collection<int, ExternalAccount>
     */
    public function accounts(?string $provider = null): Collection
    {
        $connections = $this->connectionIds($provider);

        if ($connections->isEmpty()) {
            return collect();
        }

        return ExternalAccount::withoutGlobalScopes()
            ->whereIn('provider_connection_id', $connections)
            ->where('account_type', 'ad_account')
            ->where('status', 'active')
            ->tap(fn ($q) => $this->assignment->scopeToAssigned($q))
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, string>
     */
    public function connectionIds(?string $provider = null): Collection
    {
        return ProviderConnection::withoutGlobalScopes()
            ->where('status', 'connected')
            ->when($provider, fn ($q, $p) => $q->where('provider', $p))
            ->pluck('id');
    }
}
