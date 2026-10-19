<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Metrics\Jobs\FetchPeriodReachJob;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\PeriodReach;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * REACH-PERIOD-001 — the provider's reach for a displayed scope and window, or nothing.
 *
 * A scope has a period reach only when ONE provider answer describes exactly the delivery the scope
 * shows:
 *
 *   - one external campaign → that campaign's reach for the window;
 *   - one ad account, where the scope holds every campaign that account delivered in the window →
 *     the account's reach for the window. An account shared with another project, or a scope filtered
 *     to some of its campaigns, is NOT described by the account figure, so it gets none;
 *   - anything wider — two accounts, two platforms — has no deduplicated reach anywhere: no provider
 *     knows who another provider reached. It stays «—».
 *
 * A window never fetched is requested (queued) and reads «—» until the provider has answered.
 */
final class PeriodReachReader
{
    /*
     * CAMPAIGN-INTELLIGENCE-HUB — «no N+1». A breakdown asks this once per row, and every row of one
     * account and window wants the same stored answers, the same «was it ever asked», the same account
     * and the same delivered-campaign list. They are read once per (account, window) for the life of
     * the request (the reader is bound `scoped`), so a list of fifteen campaigns costs what a list of
     * three does. The answers are identical to reading each one fresh.
     */

    /** @var array<string, Collection<int, PeriodReach>> */
    private array $rows = [];

    /** @var array<string, list<string>> */
    private array $delivered = [];

    /** @var array<string, true> */
    private array $requested = [];

    /**
     * @param  list<array{account: string, campaign: string|null}>  $delivery  the (account, external campaign) pairs the scope's rows hold
     * @return array{reach: float, frequency: float|null}|null
     */
    public function forScope(array $delivery, float $impressions, Carbon $from, Carbon $to): ?array
    {
        $accounts = array_values(array_unique(array_column($delivery, 'account')));
        $campaigns = array_values(array_unique(array_filter(array_column($delivery, 'campaign'))));

        if (count($accounts) !== 1) {
            return null;
        }

        $account = (string) $accounts[0];

        $row = count($campaigns) === 1
            ? $this->stored($account, PeriodReach::CAMPAIGN, $from, $to, campaignId: (string) $campaigns[0])
            : null;

        if ($row === null && $campaigns !== [] && $this->coversTheAccount($account, $campaigns, $from, $to)) {
            $row = $this->stored($account, PeriodReach::ACCOUNT, $from, $to);
        }

        if ($row === null || $row->state !== PeriodReach::REPORTED || ! is_numeric($row->reach) || (float) $row->reach <= 0) {
            return null;
        }

        $reach = (float) $row->reach;

        return [
            'reach' => $reach,
            'frequency' => $impressions > 0 ? round($impressions / $reach, 2) : null,
        ];
    }

    /** The stored answer for this exact window, requesting it when it has never been asked. */
    private function stored(string $account, string $grain, Carbon $from, Carbon $to, ?string $campaignId = null): ?PeriodReach
    {
        $all = $this->answers($account, $from, $to);
        $row = $all->first(static fn (PeriodReach $r): bool => $r->grain === $grain
            && ($campaignId === null || (string) $r->external_campaign_id === $campaignId));

        if ($row === null && $all->isEmpty()) {
            $this->request($account, $from, $to);
        }

        return $row;
    }

    /**
     * Every stored answer for this account and exact window — what «was it ever asked» reads too: an
     * account with any row for the window has been asked.
     *
     * @return Collection<int, PeriodReach>
     */
    private function answers(string $account, Carbon $from, Carbon $to): Collection
    {
        $key = $account.'|'.$from->toDateString().'|'.$to->toDateString();

        return $this->rows[$key] ??= PeriodReach::withoutGlobalScopes()
            ->where('external_account_id', $account)
            ->whereDate('date_from', $from->toDateString())
            ->whereDate('date_to', $to->toDateString())
            ->get();
    }

    private function request(string $account, Carbon $from, Carbon $to): void
    {
        $key = $account.'|'.$from->toDateString().'|'.$to->toDateString();
        if (isset($this->requested[$key])) {
            return;
        }
        $this->requested[$key] = true;

        $model = ExternalAccount::withoutGlobalScopes()->find($account);

        if ($model === null || ! app(PeriodReachFetcher::class)->supports($model, $from, $to)) {
            return;
        }

        FetchPeriodReachJob::dispatch($account, $from->toDateString(), $to->toDateString());
    }

    /**
     * Whether the scope's campaigns are every campaign this account delivered in the window —
     * across every project, because the provider's account reach counts all of them.
     *
     * @param  list<string>  $campaigns
     */
    private function coversTheAccount(string $account, array $campaigns, Carbon $from, Carbon $to): bool
    {
        $delivered = $this->delivered[$account.'|'.$from->toDateString().'|'.$to->toDateString()] ??= DailyMetric::withoutGlobalScopes()
            ->where('external_account_id', $account)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('external_campaign_id')
            ->distinct()
            ->pluck('external_campaign_id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();

        return array_diff($delivered, $campaigns) === [];
    }
}
