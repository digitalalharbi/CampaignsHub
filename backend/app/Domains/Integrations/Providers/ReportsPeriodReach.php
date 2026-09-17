<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

/**
 * REACH-PERIOD-001 — a connector whose API deduplicates reach over an arbitrary date range.
 *
 * Implemented only where the provider's own documentation says the figure returned for a range is
 * deduplicated across that range (one person counted once however many days they were reached). A
 * provider that can only return daily reach does not implement this, and its period reach stays «—».
 *
 * Grains are stated by the connector, not assumed: a platform may deduplicate per campaign and not
 * per account, and asking for a grain it does not deduplicate would store a sum under the name reach.
 */
interface ReportsPeriodReach
{
    public const ACCOUNT = 'account';

    public const CAMPAIGN = 'campaign';

    /** @return list<string> the grains this provider deduplicates reach over a range at */
    public function periodReachGrains(): array;

    /**
     * Whether the provider documents a deduplicated reach for THIS window — its length, and how far
     * back it starts. A window outside what the provider states it answers is never asked for.
     */
    public function periodReachWindowSupported(string $from, string $to): bool;

    /**
     * One row per entity of `$grain` that the provider returned for the window.
     *
     * `external_id` is the platform's id (the ad account id itself at account grain). `reach` is null
     * when the provider returned the entity without a reach.
     *
     * @return list<array{external_id: string, reach: float|null, impressions: float|null, frequency: float|null}>
     */
    public function periodReach(string $adAccountId, string $grain, string $from, string $to): array;
}
