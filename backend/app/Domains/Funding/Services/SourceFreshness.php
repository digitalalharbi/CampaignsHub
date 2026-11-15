<?php

declare(strict_types=1);

namespace App\Domains\Funding\Services;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Support\AdPlatforms;
use Illuminate\Support\Carbon;

/**
 * BUDGET-FRESHNESS-001 — does a budget or a limit rest on a platform whose data cannot be trusted right now?
 *
 * A figure is marked when any ad account the project has selected, inside the scope asked about, either
 *  - `failed`: its latest sync attempt ended in a failure category (the account row carries it, null on success), or
 *  - `old`: its last SUCCESSFUL sync is older than two hours, or
 *  - `never`: it has never synced.
 * Nothing here guesses a cause: the failure category is passed on as the sync recorded it, and the UI states it with the
 * existing provider-error labels.
 */
final class SourceFreshness
{
    public const MAX_AGE_HOURS = 2;

    /** failed | access_lost | never | old — or null when the account's data can be trusted right now. */
    public static function reason(ExternalAccount $a, Carbon $now): ?string
    {
        return match (true) {
            $a->access_lost_at !== null => 'access_lost',
            $a->last_sync_error_category !== null => 'failed',
            $a->last_synced_at === null => 'never',
            $a->last_synced_at->lt($now->copy()->subHours(self::MAX_AGE_HOURS)) => 'old',
            default => null,
        };
    }

    /**
     * @param  list<string>|null  $providers  limit to these platforms (project scope: null = every selected ad account)
     * @param  list<string>|null  $accountIds  limit to these external account ids
     * @return array{stale: bool, items: list<array{provider: string, account: string|null, reason: string, error_category: string|null, last_synced_at: string|null}>}
     */
    public function forProject(string $projectId, ?array $providers = null, ?array $accountIds = null, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $wanted = $providers === null ? null : array_map(static fn (string $p): string => AdPlatforms::canonical($p), $providers);

        $items = ExternalAccount::query()
            ->whereIn('id', BoundAccountVisibility::activeAccountIds($projectId))
            ->where('account_type', 'ad_account')
            ->get(['id', 'provider', 'name', 'external_id', 'last_synced_at', 'last_sync_error_category', 'access_lost_at'])
            ->filter(static function (ExternalAccount $a) use ($wanted, $accountIds): bool {
                if ($wanted !== null && ! in_array(AdPlatforms::canonical((string) $a->provider), $wanted, true)) {
                    return false;
                }

                return $accountIds === null || in_array((string) $a->id, $accountIds, true) || in_array((string) $a->external_id, $accountIds, true);
            })
            ->map(static function (ExternalAccount $a) use ($now): ?array {
                $reason = self::reason($a, $now);

                return $reason === null ? null : [
                    'provider' => AdPlatforms::canonical((string) $a->provider),
                    'account' => $a->name,
                    'reason' => $reason,
                    'error_category' => $a->last_sync_error_category,
                    'last_synced_at' => $a->last_synced_at?->toIso8601String(),
                ];
            })
            ->filter()->values()->all();

        return ['stale' => $items !== [], 'items' => $items];
    }
}
