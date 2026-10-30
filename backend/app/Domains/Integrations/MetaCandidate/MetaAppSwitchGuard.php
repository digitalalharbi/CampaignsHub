<?php

declare(strict_types=1);

namespace App\Domains\Integrations\MetaCandidate;

use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\MetaCredentialProfile;
use App\Domains\Integrations\OAuth\PlatformCredentials;

/**
 * META-APP-SWITCH-GUARD-001 — may the Live Meta app's identity change to `$targetAppId` without stranding a customer connection?
 *
 * Meta tokens are app-scoped. Every Live connection is refreshed and called with whatever identity Live has at that moment, so moving
 * Live to another App ID leaves each token the current app issued unrefreshable: those connections stop syncing until their owners
 * reconnect. Nothing is deleted, but 17 accounts going quiet is the harm this exists to refuse.
 *
 * Which app issued a token is read from the connection itself (`issuing_app_id`, written whenever a token is stored), never inferred
 * from when the connection was created. A connection with no recorded issuer predates the column and has only ever been refreshed by
 * the Live app in force since then, so it counts as issued by the CURRENT app — refusing on «unknown» is the safe side.
 *
 * Counted, per profile: that profile's Meta connections (Live: `app_profile` NULL or `live`; SaaS: `saas`) that are not revoked,
 * issued by its current App ID or by nobody on record, unless the target IS that App ID. Another profile's connections have their own
 * app and never count. Every path that can change a profile's App ID asks this: promote, rollback, the Live provider console (update,
 * rotate, clear) and the SaaS app's own settings.
 */
final class MetaAppSwitchGuard
{
    public const REASON = 'connections_issued_by_current_app';

    /**
     * @param  MetaCredentialProfile  $profile  whose identity would change: Live (promote, rollback, the provider console) or Saas
     *                                          (its own settings page). Only that profile's connections can be stranded by it.
     * @return array{safe: bool, reason: string|null, affected: int, unverified: int, current_app_hint: string|null, target_app_hint: string|null}
     */
    public function check(?string $targetAppId, MetaCredentialProfile $profile = MetaCredentialProfile::Live): array
    {
        $current = PlatformCredentials::forMeta($profile)->get('client_id');
        $target = $targetAppId === '' ? null : $targetAppId;
        $hints = ['current_app_hint' => self::hint($current), 'target_app_hint' => self::hint($target)];

        if ($target !== null && $target === $current) {
            return ['safe' => true, 'reason' => null, 'affected' => 0, 'unverified' => 0, ...$hints];
        }

        $dependent = ProviderConnection::withoutGlobalScopes()
            ->where('provider', 'meta')
            ->when(
                $profile === MetaCredentialProfile::Live,
                fn ($q) => $q->where(fn ($w) => $w->whereNull('app_profile')->orWhere('app_profile', MetaCredentialProfile::Live->value)),
                fn ($q) => $q->where('app_profile', $profile->value),
            )
            ->where('status', '!=', 'revoked')
            ->where(fn ($q) => $q->whereNull('issuing_app_id')->when($current !== null, fn ($w) => $w->orWhere('issuing_app_id', $current)))
            ->when($target !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('issuing_app_id')->orWhere('issuing_app_id', '!=', $target)));

        $affected = (clone $dependent)->count();
        $unverified = (clone $dependent)->whereNull('issuing_app_id')->count();

        return [
            'safe' => $affected === 0,
            'reason' => $affected === 0 ? null : self::REASON,
            'affected' => $affected,
            'unverified' => $unverified,
            ...$hints,
        ];
    }

    /** The last four characters only: enough to tell two apps apart, never enough to be the ID. */
    private static function hint(?string $appId): ?string
    {
        return $appId === null ? null : mb_substr($appId, -4);
    }
}
