<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Touchpoints;

use App\Support\AdPlatforms;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * TOUCHPOINT-EVIDENCE-001 — the one way a touchpoint enters the system.
 *
 * Three rules are enforced here and nowhere else, so no caller can forget them:
 *
 *  1. A visitor identifier is kept only with the visitor's consent. Without it the row keeps what the click
 *     itself carried and the identifier is dropped, whatever the caller passed.
 *  2. A touchpoint with no consent decision at all (`unknown`) or with consent refused (`denied`) is recorded
 *     as that, with no identifier, and never becomes eligible evidence for a model (see {@see OrderJourneys}).
 *  3. Recording the same event twice is the same touchpoint: the dedupe key is the source and its reference,
 *     and a second call returns the first row unchanged.
 */
final class TouchpointRecorder
{
    private const SOURCES = ['smart_link_click', 'influencer_link_click', 'ad_click_id', 'first_party_event'];

    private const CONSENT = [MarketingTouchpoint::GRANTED, MarketingTouchpoint::NOT_REQUIRED, MarketingTouchpoint::DENIED, MarketingTouchpoint::UNKNOWN];

    /**
     * @param  array<string,mixed>  $data  source_type, source_ref, channel, occurred_at, consent_state, plus optional
     *                                     external_campaign_id, smart_link_id, influencer_asset_id, click_id,
     *                                     click_id_provider, tracking_token, utm_*, visitor_key
     */
    public function record(string $tenantId, string $projectId, array $data, ?Carbon $now = null): MarketingTouchpoint
    {
        $source = (string) ($data['source_type'] ?? '');
        $ref = trim((string) ($data['source_ref'] ?? ''));
        $channel = trim((string) ($data['channel'] ?? ''));
        $consent = (string) ($data['consent_state'] ?? MarketingTouchpoint::UNKNOWN);

        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException("Unknown touchpoint source: {$source}");
        }
        if ($ref === '' || $channel === '') {
            throw new InvalidArgumentException('A touchpoint needs a source reference and a channel.');
        }
        if (! in_array($consent, self::CONSENT, true)) {
            throw new InvalidArgumentException("Unknown consent state: {$consent}");
        }
        if (! isset($data['occurred_at'])) {
            throw new InvalidArgumentException('A touchpoint needs the time it happened.');
        }

        $dedupe = hash('sha256', $source.'|'.$ref);
        $existing = MarketingTouchpoint::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('dedupe_key', $dedupe)->first();
        if ($existing !== null) {
            return $existing;
        }

        $canonical = AdPlatforms::canonical($channel);
        $paid = in_array($canonical, AdPlatforms::ORDER, true);

        // Rule 1 and 2: the identifier survives only a granted consent.
        $visitor = $consent === MarketingTouchpoint::GRANTED && ($data['visitor_key'] ?? null) !== null
            ? substr((string) $data['visitor_key'], 0, 64)
            : null;

        $provider = isset($data['click_id_provider']) ? AdPlatforms::canonical((string) $data['click_id_provider']) : null;

        return MarketingTouchpoint::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'source_type' => $source,
            'source_ref' => substr($ref, 0, 128),
            'dedupe_key' => $dedupe,
            'channel' => $paid ? $canonical : mb_strtolower($channel),
            'is_paid' => $paid,
            'external_campaign_id' => $data['external_campaign_id'] ?? null,
            'smart_link_id' => $data['smart_link_id'] ?? null,
            'influencer_asset_id' => $data['influencer_asset_id'] ?? null,
            'click_id' => isset($data['click_id']) ? substr((string) $data['click_id'], 0, 255) : null,
            'click_id_provider' => $provider,
            'tracking_token' => isset($data['tracking_token']) ? substr((string) $data['tracking_token'], 0, 64) : null,
            'utm_source' => $data['utm_source'] ?? null,
            'utm_medium' => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'utm_content' => $data['utm_content'] ?? null,
            'consent_state' => $consent,
            'visitor_key' => $visitor,
            'occurred_at' => Carbon::parse($data['occurred_at']),
            'recorded_at' => $now ?? Carbon::now(),
        ]);
    }
}
