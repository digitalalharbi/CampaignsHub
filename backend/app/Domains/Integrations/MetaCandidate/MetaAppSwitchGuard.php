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
 * Counted: Meta connections on the Live profile (`app_profile` NULL or `live`) that are not revoked, issued by the current Live App ID
 * or by nobody on record, unless the target IS that App ID. SaaS and Candidate connections have their own apps and never count.
 */
final class MetaAppSwitchGuard
{
    public const REASON = 'connections_issued_by_current_app';

    /** @return array{safe: bool, reason: string|null, affected: int, unverified: int, current_app_hint: string|null, target_app_hint: string|null} */
    public function check(?string $targetAppId): array
    {
        $current = PlatformCredentials::forMeta(MetaCredentialProfile::Live)->get('client_id');
        $target = $targetAppId === '' ? null : $targetAppId;
        $hints = ['current_app_hint' => self::hint($current), 'target_app_hint' => self::hint($target)];

        if ($target !== null && $target === $current) {
            return ['safe' => true, 'reason' => null, 'affected' => 0, 'unverified' => 0, ...$hints];
        }

        $dependent = ProviderConnection::withoutGlobalScopes()
            ->where('provider', 'meta')
            ->where(fn ($q) => $q->whereNull('app_profile')->orWhere('app_profile', MetaCredentialProfile::Live->value))
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
