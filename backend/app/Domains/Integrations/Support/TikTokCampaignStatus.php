<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Support;

use App\Domains\Campaigns\Enums\CampaignStatus;

/**
 * TIKTOK-CAMPAIGN-STATUS-001 — a TikTok campaign's state, from the two fields TikTok sends, onto the canonical status.
 *
 * TikTok sends `operation_status` as `ENABLE` / `DISABLE`. The generic vocabulary in {@see CampaignStatus::fromProvider()}
 * knows `enabled` / `disabled`, so every TikTok campaign landed `unknown` — 11 on «حلويات برديسي», one of them spending,
 * and an `unknown` campaign is not a running one to any rule that asks.
 *
 * Every value below is TikTok's own, from its Business API SDK spec (github.com/tiktok/tiktok-business-api-sdk):
 *  - `yml_files/smart_plus_campaign_status_update.yml`, enum `StatusOptType`: ENABLE, DISABLE, DELETE;
 *  - `yml_files/smart_plus_campaign_get.yml`, enum `CampaignSecondaryStatus`: the delivery states.
 * None is invented. A value that is not here stays `unknown` and is returned by name, so the sync can log it.
 *
 * The delivery state is the more specific answer and wins: a campaign switched on but under review or refused is not
 * delivering. «Not delivering» has no canonical word of its own; it is `paused` (not running, action needed — TikTok's
 * «Not delivering»), and the raw values stay on the external campaign. A daily budget spent is the exception: the
 * campaign resumes tomorrow, so it stays `active` and carries a `budget_exhausted` signal instead.
 */
final class TikTokCampaignStatus
{
    /** `operation_status` — StatusOptType. */
    private const OPERATION = [
        'ENABLE' => CampaignStatus::Active,
        'DISABLE' => CampaignStatus::Paused,
        'DELETE' => CampaignStatus::Archived,
    ];

    /** `secondary_status` — CampaignSecondaryStatus. Null: a filter aggregate, not a state; the switch decides. */
    private const SECONDARY = [
        'CAMPAIGN_STATUS_ENABLE' => CampaignStatus::Active,
        'CAMPAIGN_STATUS_DISABLE' => CampaignStatus::Paused,
        'CAMPAIGN_STATUS_DELETE' => CampaignStatus::Archived,
        // «Pending»: under review, no action needed yet.
        'CAMPAIGN_STATUS_ADVERTISER_AUDIT' => CampaignStatus::Pending,
        'ADVERTISER_CONTRACT_PENDING' => CampaignStatus::Pending,
        'CAMPAIGN_STATUS_AWAITING_RELEASE' => CampaignStatus::Pending,
        // «Not delivering»: budget, a refusal, a suspension, or something the campaign depends on is unavailable.
        // A DAILY budget spent: the campaign resumes tomorrow, so it is running (see SIGNALS).
        'CAMPAIGN_STATUS_BUDGET_EXCEED' => CampaignStatus::Active,
        'CAMPAIGN_STATUS_ADVERTISER_AUDIT_DENY' => CampaignStatus::Paused,
        'ADVERTISER_ACCOUNT_PUNISH' => CampaignStatus::Paused,
        'CAMPAIGN_STATUS_REVIEW_DISAPPROVED' => CampaignStatus::Paused,
        'CAMPAIGN_STATUS_AD_UNAVAILABLE' => CampaignStatus::Paused,
        'CAMPAIGN_STATUS_PRODUCT_UNAVAILABLE' => CampaignStatus::Paused,
        'CAMPAIGN_STATUS_TIKTOK_SHOP_UNAVAILABLE' => CampaignStatus::Paused,
        'CAMPAIGN_STATUS_TTS_TT_ASSET_UNAVAILABLE' => CampaignStatus::Paused,
        'CAMPAIGN_STATUS_TTS_TT_IDENTITY_UNAVAILABLE' => CampaignStatus::Paused,
        'CAMPAIGN_STATUS_IDENTITY_USED_BY_GMV_MAX_AD' => CampaignStatus::Paused,
        'CAMPAIGN_STATUS_IDENTITY_USED_BY_LIVE_GMV_MAX' => CampaignStatus::Paused,
        'CAMPAIGN_STATUS_LIVE_GMV_MAX_AUTHORIZATION_CANCEL' => CampaignStatus::Paused,
        'CAMPAIGN_STATUS_PRODUCT_GMV_MAX_AUTHORIZATION_CANCEL' => CampaignStatus::Paused,
        'CAMPAIGN_STATUS_PRODUCT_USED_BY_PRODUCT_GMV_MAX' => CampaignStatus::Paused,
        // Filter aggregates TikTok documents in the same enum; they describe a query, not a campaign.
        'CAMPAIGN_STATUS_ALL' => null,
        'CAMPAIGN_STATUS_NOT_DELETE' => null,
    ];

    /**
     * What a status says beyond running / not running, kept beside it rather than folded into it.
     *
     * A daily budget spent is still a running campaign (mapping it to «paused» made it flap every day and its alerts close at
     * night and reopen in the morning), and a suspended account is not a campaign somebody paused: each is named.
     */
    private const SIGNALS = [
        'CAMPAIGN_STATUS_BUDGET_EXCEED' => 'budget_exhausted',
        'ADVERTISER_ACCOUNT_PUNISH' => 'account_suspended',
    ];

    /**
     * @return array{status: string, signal: string|null, unmapped: array<string,string>|null} the canonical value, what it says beyond
     *                                                                                         that, and any value TikTok sent that is not documented
     */
    public static function resolve(?string $operation, ?string $secondary): array
    {
        $operation = self::clean($operation);
        $secondary = self::clean($secondary);
        $unmapped = [];

        $fromSecondary = null;
        if ($secondary !== null) {
            if (array_key_exists($secondary, self::SECONDARY)) {
                $fromSecondary = self::SECONDARY[$secondary];
            } else {
                $unmapped['secondary_status'] = $secondary;
            }
        }

        $fromOperation = null;
        if ($operation !== null) {
            if (isset(self::OPERATION[$operation])) {
                $fromOperation = self::OPERATION[$operation];
            } else {
                $unmapped['operation_status'] = $operation;
            }
        }

        $status = $fromSecondary ?? $fromOperation ?? CampaignStatus::Unknown;

        return [
            'status' => $status->value,
            'signal' => $secondary === null ? null : (self::SIGNALS[$secondary] ?? null),
            'unmapped' => $unmapped === [] ? null : $unmapped,
        ];
    }

    private static function clean(?string $value): ?string
    {
        $value = strtoupper(trim((string) $value));

        return $value === '' ? null : $value;
    }
}
