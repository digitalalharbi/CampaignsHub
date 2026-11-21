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
    ) {}

    /**
     * @param  Collection<int, mixed>|array<int, mixed>  $runs  this account's runs, newest first
     */
    public static function fromModel(ExternalAccount $a, string $binding, bool $syncEnabled, Collection|array $runs = []): self
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
        );
    }
}
