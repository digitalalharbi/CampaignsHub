<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services\Attention;

use App\Domains\Campaigns\Enums\CampaignObjective;

/**
 * REPORT-RECOMMENDATION-BLOCKS-001 — what of the attention list a CLIENT may read.
 *
 * One function, called by every client surface: the live link, the shared snapshot, the client PDF
 * and the executive view. A block that one of them filtered and another did not would be the same
 * report saying two different things to the same client.
 *
 * ## The rule
 *
 *   - an item the operator HID never reaches a client;
 *   - an operator-internal item (budget shifts, bidding, retargeting) reaches a client ONLY when the
 *     operator approved that item;
 *   - a client-safe item reaches a client unless hidden.
 *
 * Whether an item is operator-internal is read from its ACTION in `AttentionActions`, never from the
 * item's own `audience` field — a stored item is data, and data does not get to declare itself safe.
 * The operator's fields (`audience`, `decision`) are removed from what a client receives.
 */
final class AttentionAudience
{
    /** The decisions an operator can take on one item. */
    public const DECISIONS = ['approved', 'hidden'];

    /** Money-derived metrics a link that hides spend must not show, and those that also need revenue. */
    private const SPEND_METRICS = ['cpm', 'cpc', 'cpl', 'cpa', 'cost_per_lpv', 'roas'];

    private const REVENUE_METRICS = ['roas'];

    /**
     * @param  mixed  $items  whatever the snapshot holds — an old snapshot holds nothing
     * @param  array<string,string>  $decisions  item key => approved|hidden, the CURRENT decisions
     * @return list<array<string,mixed>>
     */
    public static function forClient(mixed $items, array $decisions = []): array
    {
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['key'] ?? null) || ! is_string($item['action'] ?? null)) {
                continue;
            }
            if (! AttentionActions::isKnown($item['action'])) {
                continue;
            }

            $decision = $decisions[$item['key']] ?? ($item['decision'] ?? null);
            if ($decision === 'hidden') {
                continue;
            }
            if (! AttentionActions::isClientSafe($item['action']) && $decision !== 'approved') {
                continue;
            }

            unset($item['audience'], $item['decision']);
            $out[] = $item;
        }

        return $out;
    }

    /**
     * The operator's view: every item, each stamped with its current decision and its audience as the
     * catalogue decides it.
     *
     * @param  list<array<string,mixed>>  $items
     * @param  array<string,string>  $decisions
     * @return list<array<string,mixed>>
     */
    public static function forOperator(array $items, array $decisions = []): array
    {
        return array_map(static function (array $item) use ($decisions): array {
            $item['audience'] = AttentionActions::isClientSafe((string) ($item['action'] ?? '')) ? 'client' : 'operator';
            $item['decision'] = $decisions[$item['key']] ?? null;

            return $item;
        }, $items);
    }

    /**
     * A link that hides spend or revenue must not publish them through a cost or a ROAS.
     *
     * A KPI derived from the hidden figure leaves the block; a block whose JUDGED metric left has no
     * evidence and leaves with it; an impact is money, so it leaves with spend.
     *
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    public static function redact(array $items, bool $hideSpend, bool $hideRevenue): array
    {
        if (! $hideSpend && ! $hideRevenue) {
            return $items;
        }

        $out = [];
        foreach ($items as $item) {
            $keep = true;
            $kpis = [];
            foreach ($item['kpis'] ?? [] as $kpi) {
                $key = (string) ($kpi['key'] ?? '');
                $hidden = ($hideSpend && in_array($key, self::SPEND_METRICS, true))
                    || ($hideRevenue && in_array($key, self::REVENUE_METRICS, true));
                if ($hidden) {
                    if (($kpi['primary'] ?? false) === true) {
                        $keep = false;
                    }

                    continue;
                }
                $kpis[] = $kpi;
            }
            if (! $keep) {
                continue;
            }
            $item['kpis'] = $kpis;
            if ($hideSpend) {
                $item['impact'] = null;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * The drill-down: platform → content. Up to three pieces of content from the SAME report's
     * already client-bounded ad list, on the item's platform and objective family.
     *
     * Only a name and the share-bound content handle are copied — never an id the ad list itself
     * would not publish. Where the content section is off, the list is empty and the drill-down
     * stops at the platform.
     *
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    public static function withEvidence(array $items, mixed $ads): array
    {
        $ads = is_array($ads) ? array_values(array_filter($ads, 'is_array')) : [];

        return array_map(static function (array $item) use ($ads): array {
            $content = [];
            foreach ($ads as $ad) {
                if ((string) ($ad['provider'] ?? '') !== (string) $item['platform']) {
                    continue;
                }
                $objective = CampaignObjective::tryFrom((string) ($ad['objective'] ?? ''));
                if ($objective !== null && $objective->family()->value !== $item['family']) {
                    continue;
                }
                $entry = ['name' => is_string($ad['name'] ?? null) ? $ad['name'] : null];
                if (is_string($ad['content_key'] ?? null)) {
                    $entry['content_key'] = $ad['content_key'];
                }
                $content[] = $entry;
                if (count($content) === 3) {
                    break;
                }
            }
            $item['evidence'] = ['platform' => $item['platform'], 'content' => $content];

            return $item;
        }, $items);
    }
}
