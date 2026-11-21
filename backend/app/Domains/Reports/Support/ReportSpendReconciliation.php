<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use App\Domains\Integrations\Services\BoundAccountVisibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SPEND-RECONCILE-RECORD-001 — «آخر مطابقة مؤكدة مع المصدر» per platform, for one live link.
 *
 * A reconciliation is a stronger claim than a sync: the platform was asked for the account's spend
 * and the stored total was set beside it. The live report carries the latest one per platform beside
 * the freshness entry, so a client can tell «we last asked the platform at 14:32» from «we last
 * CONFIRMED the figures against the platform at 14:32 — and they matched».
 *
 * ## Scope (the same `ContributorCoverage` / freshness rule)
 *
 * Only rows of the link's own project, only while the row's account is still actively bound to it
 * ({@see BoundAccountVisibility}), and, for a link narrowed to specific ad accounts, only those
 * accounts. Another project's reconciliation of the same platform can never make this link look
 * confirmed; nothing cross-project leaves this class.
 *
 * ## One query
 *
 * One grouped read of `spend_reconciliations` answers every provider; nothing per provider.
 */
final class ReportSpendReconciliation
{
    public const NEVER = 'never';

    /**
     * @param  list<string>  $providers
     * @param  list<string>|null  $accountIds  the link's ad-account ceiling; null/empty = every still-bound account of the project
     * @return array<string, array{last_reconciled_at: ?string, reconcile_status: string, reconcile_diff_pct: ?float}>
     */
    public function forProviders(string $tenantId, string $projectId, array $providers, ?array $accountIds = null): array
    {
        $providers = array_values(array_unique(array_filter($providers)));
        if ($projectId === '' || $providers === []) {
            return [];
        }

        $latest = DB::table('spend_reconciliations as r')
            ->where('r.tenant_id', $tenantId)
            ->where('r.project_id', $projectId)
            ->whereIn('r.provider', $providers)
            ->when($accountIds !== null && $accountIds !== [], fn ($q) => $q->whereIn('r.external_account_id', $accountIds))
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'r'))
            ->selectRaw('DISTINCT ON (r.provider) r.provider, r.status, r.diff_pct, r.checked_at')
            ->orderBy('r.provider')
            ->orderByDesc('r.checked_at')
            ->get()
            ->keyBy(fn ($r): string => (string) $r->provider);

        $out = [];
        foreach ($providers as $provider) {
            $row = $latest->get($provider);
            $out[$provider] = $row === null
                ? ['last_reconciled_at' => null, 'reconcile_status' => self::NEVER, 'reconcile_diff_pct' => null]
                : [
                    'last_reconciled_at' => Carbon::parse((string) $row->checked_at)->utc()->toIso8601ZuluString(),
                    'reconcile_status' => (string) $row->status,
                    'reconcile_diff_pct' => $row->diff_pct === null ? null : round((float) $row->diff_pct, 2),
                ];
        }

        return $out;
    }
}
