<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Providers\MetaConnector;
use App\Domains\Integrations\Providers\SnapchatConnector;
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
 * Meta and TikTok, whose links are short-lived; and Snapchat (CREATIVE-COVERS-COVERAGE-001), whose films carry
 * no still in the sweep — the still is one more read per film. OpenAI Ads and X expose no image to refresh. A refusal, an exhausted budget or a missing connection is
 * an answer of «no fresh link», never an exception into the queue.
 */
final class CoverSourceRefresher
{
    public function __construct(private readonly AdvertisingConnectorRegistry $registry) {}

    /**
     * CREATIVE-COVERS-COVERAGE-001 — a creative with NO link at all that its platform can still name a still for.
     *
     * Production 2026-11-07: 410 TikTok and 3,047 Snapchat creatives held no thumbnail and no asset link, so no copy
     * could ever be taken and the backfill skipped them as «the provider gives none». For these the provider does
     * give one — through a second read the sweep did not make or could not finish:
     *
     *   * TikTok: a video or image in the Asset Library (`file/video|image/ad/info/`), or a Spark post
     *     (`identity/video/info/` under the identity that owns it — kept on the creative, else the advertiser's).
     *   * Meta: a creative that names its image hash or video id.
     *   * Snapchat: any creative with a top snap — a film's still is `GET /media/{id}/thumbnail`, an image's file is
     *     the media's `download_link`. A dynamic collection is composed per product and has no file: never asked.
     *
     * Read from the row alone (no query), so the backfill and the job decide the same way.
     */
    public static function recoverable(ExternalCreative $creative): bool
    {
        if ((bool) $creative->is_demo || $creative->external_campaign_id === null) {
            return false;
        }
        $raw = (array) ($creative->raw ?? []);

        return match (strtolower((string) $creative->provider)) {
            'tiktok' => in_array($raw['media'] ?? null, ['video', 'image', 'spark'], true),
            'meta' => isset($raw['media_refs']['image_hash']) || isset($raw['media_refs']['video_id']),
            'snapchat' => ! str_contains(strtolower((string) $creative->format), 'dynamic') && strtolower((string) $creative->format) !== 'catalog',
            default => false,
        };
    }

    /** @return array<string, string>|null the fresh link columns now on the row, or null when none could be had */
    public function refresh(ExternalCreative $creative): ?array
    {
        $provider = strtolower((string) $creative->provider);
        if (! in_array($provider, ['meta', 'tiktok', 'snapchat'], true) || (bool) $creative->is_demo) {
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
                    // CREATIVE-COVERS-COVERAGE-001 — a Spark post is asked under the identity the sweep kept for it.
                    'identity' => array_filter(
                        array_intersect_key($raw, ['identity_type' => true, 'identity_id' => true, 'identity_authorized_bc_id' => true]),
                        static fn ($v): bool => is_string($v) && $v !== '',
                    ),
                ]])[$id] ?? [],
                $connector instanceof SnapchatConnector => $connector->withConnection($connection)->renewCreativeStill(
                    (string) $account->external_id,
                    $id,
                    is_string($raw['media_id'] ?? null) ? $raw['media_id'] : null,
                ),
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
