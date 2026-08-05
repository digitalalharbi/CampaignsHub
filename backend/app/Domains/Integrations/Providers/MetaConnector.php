<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\OAuth\OAuthTokens;

/**
 * Meta Marketing API (Graph v21) — Facebook and Instagram.
 *
 * The awkward part is conversions. Meta does not return a `conversions` number; it returns an
 * `actions` array of every action type the campaign produced — page engagement, video views, link
 * clicks, purchases — and a matching `action_values` array for the money. Summing all of them would
 * count a page like as a purchase, so `PURCHASE_ACTIONS` names the ones this product means by
 * "conversion", and everything else is left where it is rather than quietly inflating a ROAS.
 *
 * Awaiting credentials on this install.
 */
final class MetaConnector extends ApiAdvertisingConnector
{
    /**
     * The action types counted as a conversion.
     *
     * Both spellings are real: `purchase` comes from the pixel, `omni_purchase` from the aggregated
     * cross-surface attribution. A campaign can report either, and reading only one silently halves
     * the conversions on the accounts that report the other.
     *
     * @var list<string>
     */
    private const PURCHASE_ACTIONS = ['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase'];

    protected function platform(): string
    {
        return 'meta';
    }

    protected function fetchAdAccounts(OAuthTokens $tokens): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url('me/adaccounts'), [
                'fields' => 'id,account_id,name,currency,timezone_name,account_status',
                'limit' => 200,
            ]),
            'ad accounts',
        );

        $accounts = [];

        foreach ((array) ($body['data'] ?? []) as $a) {
            if (($a['id'] ?? null) === null) {
                continue;
            }

            $accounts[] = [
                // `id` is already `act_<number>`, which is the form every other endpoint wants.
                'external_id' => (string) $a['id'],
                'name' => (string) ($a['name'] ?? $a['id']),
                'currency' => isset($a['currency']) ? (string) $a['currency'] : null,
                'timezone' => isset($a['timezone_name']) ? (string) $a['timezone_name'] : null,
                'status' => ((int) ($a['account_status'] ?? 1)) === 1 ? 'active' : 'inactive',
                'parent_external_id' => null,
                'raw' => (array) $a,
            ];
        }

        return $accounts;
    }

    protected function fetchCampaigns(OAuthTokens $tokens, string $adAccountId): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url("{$adAccountId}/campaigns"), [
                'fields' => 'id,name,status,objective,daily_budget,lifetime_budget,start_time,stop_time',
                'limit' => 500,
            ]),
            'campaigns',
        );

        $campaigns = [];

        foreach ((array) ($body['data'] ?? []) as $c) {
            if (($c['id'] ?? null) === null) {
                continue;
            }

            $campaigns[] = [
                'external_id' => (string) $c['id'],
                'name' => (string) ($c['name'] ?? $c['id']),
                'status' => strtolower((string) ($c['status'] ?? 'unknown')),
                'objective' => isset($c['objective']) ? (string) $c['objective'] : null,
                // Budgets arrive in minor units of the account currency (halalas, cents).
                'daily_budget' => isset($c['daily_budget']) ? (float) $c['daily_budget'] / 100 : null,
                'lifetime_budget' => isset($c['lifetime_budget']) ? (float) $c['lifetime_budget'] / 100 : null,
                'currency' => null,
                'raw' => (array) $c,
            ];
        }

        return $campaigns;
    }

    protected function fetchInsights(OAuthTokens $tokens, string $adAccountId, string $from, string $to): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url("{$adAccountId}/insights"), [
                'level' => 'campaign',
                'time_increment' => 1, // one row per campaign per day
                'fields' => 'campaign_id,date_start,spend,impressions,clicks,reach,actions,action_values,video_30_sec_watched_actions',
                'time_range' => json_encode(['since' => $from, 'until' => $to], JSON_THROW_ON_ERROR),
                'limit' => 500,
            ]),
            'daily insights',
        );

        $rows = [];

        foreach ((array) ($body['data'] ?? []) as $row) {
            $campaignId = (string) ($row['campaign_id'] ?? '');

            if ($campaignId === '') {
                continue;
            }

            $rows[] = array_filter([
                'campaign_id' => $campaignId,
                'date' => (string) ($row['date_start'] ?? $from),
                'spend' => isset($row['spend']) ? (float) $row['spend'] : null,
                'impressions' => isset($row['impressions']) ? (float) $row['impressions'] : null,
                'clicks' => isset($row['clicks']) ? (float) $row['clicks'] : null,
                'reach' => isset($row['reach']) ? (float) $row['reach'] : null,
                'conversions' => $this->sumActions($row['actions'] ?? null),
                'revenue' => $this->sumActions($row['action_values'] ?? null),
                'video_views' => $this->sumActions($row['video_30_sec_watched_actions'] ?? null, all: true),
            ], static fn ($v) => $v !== null);
        }

        return $rows;
    }

    /**
     * Sum a Meta action array, counting only purchases unless told otherwise.
     *
     * Returns null rather than 0.0 when the array is absent, because "Meta sent no actions" and "Meta
     * sent zero purchases" are different facts and only the second is a figure worth storing.
     */
    private function sumActions(mixed $actions, bool $all = false): ?float
    {
        if (! is_array($actions)) {
            return null;
        }

        $total = 0.0;

        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            if (! $all && ! in_array((string) ($action['action_type'] ?? ''), self::PURCHASE_ACTIONS, true)) {
                continue;
            }

            $total += (float) ($action['value'] ?? 0);
        }

        return $total;
    }
}
