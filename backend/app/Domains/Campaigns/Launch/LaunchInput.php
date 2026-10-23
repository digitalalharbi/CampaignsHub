<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Launch;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * LAUNCH-001 — the per-provider shape of each step's input, built from one plan's spec.
 *
 * The rules are the ones `CampaignWriteController` applies to the same actions (ad-set and ad creation), so a
 * launcher can never send a provider something the control panel would have refused. The lookups are the same
 * too: a creative must belong to an account selected for this project, and a TikTok ad needs a synced ad that
 * already ran its video, because TikTok publishes under an identity this product can only learn that way.
 */
final class LaunchInput
{
    private const TIKTOK_CALLS = ['LEARN_MORE', 'SHOP_NOW', 'ORDER_NOW', 'SIGN_UP', 'CONTACT_US', 'BOOK_NOW', 'APPLY_NOW', 'VISIT_STORE'];

    /** The whole plan as the builder sends it. */
    public static function spec(array $data): array
    {
        return Validator::make($data, [
            'name' => ['required', 'string', 'min:1', 'max:250'],
            'objective' => ['required', 'string', 'max:64'],
            'objectives' => ['nullable', 'array'],
            'objectives.*' => ['string', 'max:64'],
            'accounts' => ['required', 'array', 'min:1', 'max:10'],
            'accounts.*.external_account_id' => ['required', 'string', 'max:64', 'distinct'],
            'accounts.*.daily_budget' => ['nullable', 'numeric', 'gt:0', 'max:100000000'],
            'ad_set' => ['nullable', 'array'],
            'ad_set.name' => ['required_with:ad_set', 'string', 'min:1', 'max:250'],
            'ad_set.optimization_goal' => ['nullable', 'array'],
            'ad_set.optimization_goal.*' => ['string', 'max:64'],
            'ad_set.daily_budget' => ['nullable', 'numeric', 'gt:0', 'max:100000000'],
            'ad_set.countries' => ['nullable', 'array', 'max:25'],
            'ad_set.countries.*' => ['string', 'regex:/^[A-Z]{2}$/'],
            'ad_set.bid_amount' => ['nullable', 'array'],
            'ad_set.bid_amount.*' => ['numeric', 'gt:0', 'max:10000000'],
            'ad_set.starts_at' => ['nullable', 'date'],
            'ad' => ['nullable', 'array'],
            'ad.name' => ['required_with:ad', 'string', 'min:1', 'max:250'],
            'ad.creative_id' => ['nullable', 'array'],
            'ad.creative_id.*' => ['string', 'max:64'],
            'ad.google.headlines' => ['nullable', 'array', 'max:15'],
            'ad.google.headlines.*' => ['string', 'max:30'],
            'ad.google.descriptions' => ['nullable', 'array', 'max:4'],
            'ad.google.descriptions.*' => ['string', 'max:90'],
            'ad.tiktok.ad_text' => ['nullable', 'string', 'max:100'],
            'ad.tiktok.call_to_action' => ['nullable', Rule::in(self::TIKTOK_CALLS)],
            'tracking' => ['nullable', 'array'],
            'tracking.url' => ['nullable', 'url:https', 'max:2048'],
            'tracking.utm_source' => ['nullable', 'string', 'max:100'],
            'tracking.utm_medium' => ['nullable', 'string', 'max:100'],
            'tracking.utm_campaign' => ['nullable', 'string', 'max:100'],
            'launch' => ['sometimes', 'boolean'],
            'business_goal' => ['nullable', Rule::in(JourneyPlanner::GOALS)],
            'budget' => ['nullable', 'array'],
            'budget.mode' => ['nullable', Rule::in(BudgetSplit::MODES)],
            'budget.total' => ['nullable', 'numeric', 'gt:0', 'max:100000000'],
            'budget.currency' => ['nullable', 'string', 'size:3'],
            'budget.shares' => ['nullable', 'array', 'max:10'],
            'budget.shares.*' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ])->validate();
    }

    /** The destination with its UTM parameters, or null when the plan names no destination. */
    public static function destination(array $spec): ?string
    {
        $tracking = (array) ($spec['tracking'] ?? []);
        $url = $tracking['url'] ?? null;
        if (! is_string($url) || $url === '') {
            return null;
        }
        $params = array_filter([
            'utm_source' => $tracking['utm_source'] ?? null,
            'utm_medium' => $tracking['utm_medium'] ?? null,
            'utm_campaign' => $tracking['utm_campaign'] ?? null,
        ], static fn ($v) => is_string($v) && $v !== '');
        if ($params === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($params);
    }

    /**
     * The ad-set input for one provider — the rules `CampaignWriteController::adSet` applies.
     *
     * @param  list<string>  $goals
     *
     * @throws ValidationException
     */
    public static function adSet(array $spec, string $provider, array $goals): array
    {
        $set = (array) ($spec['ad_set'] ?? []);
        $data = [
            'name' => $set['name'] ?? null,
            'optimization_goal' => ((array) ($set['optimization_goal'] ?? []))[$provider] ?? null,
            'daily_budget' => $set['daily_budget'] ?? null,
            'countries' => $provider === 'google' ? null : ($set['countries'] ?? null),
            'bid_amount' => ((array) ($set['bid_amount'] ?? []))[$provider] ?? null,
            'starts_at' => $set['starts_at'] ?? null,
        ];
        $valid = Validator::make($data, [
            'name' => ['required', 'string', 'min:1', 'max:250'],
            'optimization_goal' => $goals === [] ? ['prohibited'] : ['required', 'string', Rule::in($goals)],
            'daily_budget' => ['nullable', 'numeric', 'gt:0', 'max:100000000'],
            'countries' => $provider === 'google' ? ['prohibited'] : ['required', 'array', 'min:1', 'max:25'],
            'countries.*' => ['string', 'regex:/^[A-Z]{2}$/'],
            'bid_amount' => $provider === 'google' ? ['required', 'numeric', 'gt:0', 'max:10000000'] : ['nullable', 'numeric', 'gt:0'],
            'starts_at' => ['nullable', 'date'],
        ])->validate();

        return array_filter($valid, static fn ($v) => $v !== null);
    }

    /**
     * The ad input for one provider — the rules `CampaignWriteController` applies per platform.
     *
     * @throws ValidationException
     */
    public static function ad(array $spec, string $provider): array
    {
        $ad = (array) ($spec['ad'] ?? []);
        $name = Validator::make(['name' => $ad['name'] ?? null], ['name' => ['required', 'string', 'min:1', 'max:250']])->validate();
        $url = self::destination($spec);

        if ($provider === 'google') {
            $google = (array) ($ad['google'] ?? []);
            $data = Validator::make(['headlines' => $google['headlines'] ?? null, 'descriptions' => $google['descriptions'] ?? null, 'url' => $url], [
                'headlines' => ['required', 'array', 'min:3', 'max:15'],
                'headlines.*' => ['required', 'string', 'distinct', 'max:30'],
                'descriptions' => ['required', 'array', 'min:2', 'max:4'],
                'descriptions.*' => ['required', 'string', 'distinct', 'max:90'],
                'url' => ['required', 'url:https', 'max:2048'],
            ])->validate();

            return $name + ['headlines' => array_values($data['headlines']), 'descriptions' => array_values($data['descriptions']), 'url' => (string) $data['url']];
        }

        $creativeId = ((array) ($ad['creative_id'] ?? []))[$provider] ?? null;
        $creative = Validator::make(['creative_id' => $creativeId], ['creative_id' => ['required', 'string', 'max:64']])->validate();
        $external = BoundAccountVisibility::apply(ExternalCreative::query(), 'external_creatives')
            ->where('provider', $provider)->whereKey($creative['creative_id'])->value('external_creative_id');
        if ($external === null || $external === '') {
            throw ValidationException::withMessages(['creative_id' => ['That creative is not one of this project\'s on this platform.']]);
        }

        if ($provider === 'tiktok') {
            $tiktok = (array) ($ad['tiktok'] ?? []);
            $data = Validator::make(['ad_text' => $tiktok['ad_text'] ?? null, 'call_to_action' => $tiktok['call_to_action'] ?? null, 'url' => $url], [
                'ad_text' => ['required', 'string', 'min:1', 'max:100'],
                'call_to_action' => ['required', Rule::in(self::TIKTOK_CALLS)],
                'url' => ['required', 'url:https', 'max:2048'],
            ])->validate();
            $source = BoundAccountVisibility::applyThroughCampaign(ExternalAd::query(), 'external_ads.external_campaign_id', 'external_ads.project_id')
                ->where('provider', 'tiktok')->where('creative_id', $creative['creative_id'])
                ->orderByDesc('last_synced_at')->value('external_id');
            if ($source === null || $source === '') {
                throw ValidationException::withMessages(['creative_id' => ['TikTok publishes an ad under an identity. No synced TikTok ad has run this video, so its identity is not known here.']]);
            }

            return $name + ['creative_external_id' => (string) $external, 'ad_text' => (string) $data['ad_text'], 'call_to_action' => (string) $data['call_to_action'], 'url' => (string) $data['url'], 'source_ad_external_id' => (string) $source];
        }

        return $name + ['creative_external_id' => (string) $external];
    }
}
