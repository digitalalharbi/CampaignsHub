<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

/**
 * AD-MEDIA-RECOVERY-002 — a provider that can re-sign a creative's media by the creative's own id.
 *
 * A platform link expires (`asset_expires_at`), and the only refresh was a whole-account structure
 * sweep every six hours that re-reads a creative only if an ad the account still LISTS points at it.
 * A creative on a paused, archived or unlisted ad therefore stayed `expired` for good. This asks for
 * exactly the creatives whose links are dying, by id, and returns them in the connector's own
 * creative shape so the importer's media rules apply unchanged.
 *
 * The connector must already be bound to a connection (`withConnection`). A refusal is thrown, not
 * swallowed: the caller records it so «expired» can say whose door is closed.
 */
interface RefreshesCreativeMedia
{
    /**
     * @param  list<string>  $externalCreativeIds  the provider's creative ids, all under one ad account
     * @return array<string, array<string, mixed>> creative id → creative (external_id, format, asset_url, …)
     */
    public function refreshCreativeMedia(string $adAccountId, array $externalCreativeIds): array;
}
