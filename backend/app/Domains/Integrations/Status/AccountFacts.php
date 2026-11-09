<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Status;

use App\Domains\Integrations\Models\ExternalAccount;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * INTEG-STATUS-MODEL-001 — what is KNOWN about one resource under an authorisation and its link to a project.
 *
 * `binding` is deliberately three-valued. «Never linked» and «was linked and is not now» are different
 * facts with different next steps, and a boolean `is_bound` is how they were the same word.
 */
final class AccountFacts
{
    public const BINDING_NONE = 'none';

    public const BINDING_ACTIVE = 'active';

    public const BINDING_INACTIVE = 'inactive';

    public function __construct(
        /** @var 'none'|'active'|'inactive' */
        public readonly string $binding = self::BINDING_NONE,
        public readonly bool $syncEnabled = true,
        public readonly ?CarbonInterface $accessLostAt = null,
        public readonly ?CarbonInterface $lastSyncedAt = null,
        public readonly ?CarbonInterface $lastAttemptAt = null,
        public readonly ?string $lastErrorCategory = null,
        public readonly ?CarbonInterface $nextSyncAt = null,
        /** Status of the newest FINISHED run, if any run survives. */
        public readonly ?string $latestRunStatus = null,
        public readonly ?CarbonInterface $latestRunAt = null,
        /** Start of a run still marked `running`, if any. Whether it is believable is the resolver's call. */
        public readonly ?CarbonInterface $openRunStartedAt = null,
        public readonly ?CarbonInterface $lastFailureAt = null,
        /** FX-RETRO-001 — stored money rows waiting for an exchange rate (value NULL, original kept). */
        public readonly int $withheldMoneyRows = 0,
        /** The currency those rows are waiting to be converted FROM. */
        public readonly ?string $withheldCurrency = null,
        /** Ad sets / ad groups stored under this account; null when it was not asked. */
        public readonly ?int $adSetCount = null,
        /** Whether any non-demo metric row has ever been stored for it; null when it was not asked. */
        public readonly ?bool $hasDelivery = null,
    ) {}

    /**
     * @param  Collection<int, mixed>|array<int, mixed>  $runs  this account's runs, newest first
     */
    /**
     * @param  array{withheld_rows?:int, withheld_currency?:?string, ad_sets?:int, has_delivery?:bool}|null  $evidence  from {@see AccountEvidence}
     */
    public static function fromModel(ExternalAccount $a, string $binding, bool $syncEnabled, Collection|array $runs = [], ?array $evidence = null): self
    {
        $runs = collect($runs);
        $open = $runs->first(static fn ($r): bool => $r->status === 'running');
        $finished = $runs->first(static fn ($r): bool => $r->status !== 'running');
        $failed = $runs->first(static fn ($r): bool => $r->status === 'failed');

        return new self(
            binding: $binding,
            syncEnabled: $syncEnabled,
            accessLostAt: $a->access_lost_at,
            lastSyncedAt: $a->last_synced_at,
            lastAttemptAt: $a->last_sync_attempt_at,
            lastErrorCategory: $a->last_sync_error_category,
            nextSyncAt: $a->next_sync_at,
            latestRunStatus: $finished?->status,
            latestRunAt: $finished?->started_at,
            openRunStartedAt: $open?->started_at,
            lastFailureAt: $failed === null ? null : ($failed->finished_at ?? $failed->started_at),
            withheldMoneyRows: (int) ($evidence['withheld_rows'] ?? 0),
            withheldCurrency: $evidence['withheld_currency'] ?? null,
            adSetCount: isset($evidence['ad_sets']) ? (int) $evidence['ad_sets'] : null,
            hasDelivery: isset($evidence['has_delivery']) ? (bool) $evidence['has_delivery'] : null,
        );
    }
}
