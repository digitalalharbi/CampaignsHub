<?php

declare(strict_types=1);

namespace App\Domains\Integrations\MetaCandidate;

use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\MetaCredentialProfile;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use Throwable;

/**
 * META-MULTI-APP-001 — which Meta app issued each connection's token, proven by Meta and never inferred.
 *
 * `inspect()` is STRICTLY read-only: it reads the stored token (never the vault's refreshing `fresh()`), asks Meta's `debug_token` with each
 * configured app's credentials in turn, and returns what Meta said. The `app_id` Meta returns is the proof. `record()` is the only writer,
 * called one connection at a time and only for a verified row; it sets `issuing_app_id` and `issuing_app_verified_at` and nothing else —
 * never `app_profile`, never a token.
 */
final class MetaAppAudit
{
    public const MATCHES = 'verified-matches-recorded';

    public const DIFFERS = 'verified-differs';

    public const UNRESOLVED = 'unresolved';

    public function __construct(private readonly TokenVault $vault, private readonly MetaGraphProbe $graph) {}

    /**
     * @return array{id: string, recorded_profile: string, recorded_app_id: string|null, verified_app_id: string|null, result: string, reason: string|null, registered: bool}
     */
    public function inspect(ProviderConnection $connection): array
    {
        $profile = MetaCredentialProfile::ofConnection($connection->app_profile ?? null);
        $recorded = $connection->issuing_app_id !== null && $connection->issuing_app_id !== ''
            ? (string) $connection->issuing_app_id
            : PlatformCredentials::forMeta($profile)->get('client_id');
        $row = [
            'id' => substr((string) $connection->getKey(), 0, 8),
            'recorded_profile' => $profile->value,
            'recorded_app_id' => $recorded,
            'verified_app_id' => null,
            'result' => self::UNRESOLVED,
            'reason' => null,
            'registered' => false,
        ];

        if ($connection->status === 'revoked') {
            return [...$row, 'reason' => 'revoked'];
        }

        try {
            $token = $this->vault->stored($connection)->accessToken;
        } catch (Throwable) {
            return [...$row, 'reason' => 'no_stored_token'];
        }

        // The recorded app is asked first; the other registered customer app second. Only configured apps can be asked.
        $order = $profile === MetaCredentialProfile::Saas
            ? [MetaCredentialProfile::Saas, MetaCredentialProfile::Live]
            : [MetaCredentialProfile::Live, MetaCredentialProfile::Saas];
        $asked = 0;
        $reason = 'no_credentials';
        foreach ($order as $candidate) {
            $app = PlatformCredentials::forMeta($candidate);
            if (! $app->isConfigured()) {
                continue;
            }
            $asked++;
            try {
                $debug = $this->graph->debugToken($app, $token);
            } catch (Throwable $e) {
                // Another app's secret may be refused for this token; remember why and try the next.
                $reason = $e->getMessage();

                continue;
            }
            if ($debug['is_valid'] && $debug['app_id'] !== null) {
                return [
                    ...$row,
                    'verified_app_id' => $debug['app_id'],
                    'result' => $debug['app_id'] === $recorded ? self::MATCHES : self::DIFFERS,
                    'registered' => $this->isRegistered($debug['app_id']),
                ];
            }
            $reason = MetaGraphProbe::FAIL_INVALID_TOKEN;
        }

        return [...$row, 'reason' => $asked === 0 ? 'no_credentials' : $reason];
    }

    /**
     * Record a verified issuer on ONE connection. Refuses (returns false) anything not verified or not a registered app, and a row that
     * already says exactly this. Writes two columns by primary key through the base query builder: no model events, no other column.
     *
     * @param  array<string, mixed>  $verdict  the array `inspect()` returned for this connection
     */
    public function record(ProviderConnection $connection, array $verdict): bool
    {
        if (! in_array($verdict['result'] ?? null, [self::MATCHES, self::DIFFERS], true)
            || ! is_string($verdict['verified_app_id'] ?? null) || ($verdict['registered'] ?? false) !== true) {
            return false;
        }
        if ($connection->issuing_app_id === $verdict['verified_app_id'] && $connection->issuing_app_verified_at !== null) {
            return false;
        }

        ProviderConnection::withoutGlobalScopes()->whereKey($connection->getKey())->toBase()->update([
            'issuing_app_id' => $verdict['verified_app_id'],
            'issuing_app_verified_at' => now(),
        ]);

        return true;
    }

    private function isRegistered(string $appId): bool
    {
        foreach ([MetaCredentialProfile::Live, MetaCredentialProfile::Saas] as $profile) {
            if (PlatformCredentials::forMeta($profile)->get('client_id') === $appId) {
                return true;
            }
        }

        return false;
    }
}
