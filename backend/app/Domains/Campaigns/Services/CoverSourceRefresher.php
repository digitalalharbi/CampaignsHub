<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Providers\MetaConnector;
use App\Domains\Integrations\Providers\TikTokConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Integrations\Support\ProviderErrorText;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CREATIVE-PREVIEWS-001 — a fresh provider link for ONE creative, when the stored one has lapsed.
 *
 * The cover cache can only copy a link that still answers. Meta's CDN links carry an `oe=` expiry and
 * TikTok's an `x-expires` (an image an hour, a video cover six), so a capture that runs after the link
 * died used to fail and leave the card with nothing. This asks the platform again, through the same
 * connector reads the structure sweep uses — so every call goes through `PlatformHttp::budgeted` and the
 * provider's own rate budget — and writes the fresh links back to the row exactly as the sweep would.
 *
 * Only Meta and TikTok: those are the platforms whose links are short-lived and whose connectors can name
 * one creative's media again. Snapchat's media link already answers (Owner, 2026-11-07: «سناب تظهر»), and
 * OpenAI Ads and X expose no image to refresh. A refusal, an exhausted budget or a missing connection is
 * an answer of «no fresh link», never an exception into the queue.
 */
final class CoverSourceRefresher
{
    public function __construct(private readonly AdvertisingConnectorRegistry $registry) {}

    /** @return array<string, string>|null the fresh link columns now on the row, or null when none could be had */
    public function refresh(ExternalCreative $creative): ?array
    {
        $provider = strtolower((string) $creative->provider);
        if (! in_array($provider, ['meta', 'tiktok'], true) || (bool) $creative->is_demo) {
            return null;
        }

        $account = $this->accountOf($creative);
        $connection = $account === null ? null : ProviderConnection::withoutGlobalScopes()->find($account->provider_connection_id);
        $connector = $this->registry->get($provider);
        if ($account === null || $connection === null || $connector === null) {
            return null;
        }

        $id = (string) $creative->external_creative_id;
        $raw = (array) ($creative->raw ?? []);

        try {
            $fresh = match (true) {
                $connector instanceof MetaConnector => $connector->withConnection($connection)->renewCreativeMedia((string) $account->external_id, [[
                    'external_id' => $id,
                    'format' => $creative->format,
                    'media_refs' => (array) ($raw['media_refs'] ?? []),
                ]])['creatives'][$id] ?? [],
                $connector instanceof TikTokConnector => $connector->withConnection($connection)->renewCreativeMedia((string) $account->external_id, [[
                    'external_id' => $id,
                    'media' => is_string($raw['media'] ?? null) ? $raw['media'] : null,
                ]])[$id] ?? [],
                default => [],
            };
        } catch (Throwable $e) {
            Log::info('Creative cover source could not be renewed.', ['creative' => (string) $creative->getKey(), 'reason' => ProviderErrorText::forDisplay($e->getMessage())]);

            return null;
        }

        $links = array_filter(
            array_intersect_key($fresh, ['asset_url' => true, 'video_url' => true, 'thumbnail_url' => true]),
            static fn ($v): bool => is_string($v) && str_starts_with($v, 'https://'),
        );
        if ($links === []) {
            return null;
        }

        $expires = $fresh['asset_expires_at'] ?? null;
        $creative->forceFill([...$links, 'asset_expires_at' => is_string($expires) ? $expires : null])->save();

        return $links;
    }

    private function accountOf(ExternalCreative $creative): ?ExternalAccount
    {
        $campaign = $creative->external_campaign_id === null ? null : ExternalCampaign::withoutGlobalScopes()->find($creative->external_campaign_id);
        $accountId = $campaign?->external_account_id;

        return $accountId === null ? null : ExternalAccount::withoutGlobalScopes()->find($accountId);
    }
}
