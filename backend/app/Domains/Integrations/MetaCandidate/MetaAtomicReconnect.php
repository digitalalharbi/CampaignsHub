<?php

declare(strict_types=1);

namespace App\Domains\Integrations\MetaCandidate;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\AuthorizationState;
use App\Domains\Integrations\OAuth\MetaCredentialProfile;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\PlatformOAuth;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Services\AccountAssignment;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * META-MULTI-APP-001 — reconnect an EXISTING Meta connection through the PRIMARY app, all-or-nothing.
 *
 * ## The guarantee
 *
 * Starting changes nothing in the database: it only issues a single-use state bound to the connection. The callback exchanges the code with
 * the new app, then PROVES — before one row is written — that (1) the product's permissions were granted, (2) the new token lists every ad
 * account the connection currently feeds, and (3) Meta says the token belongs to the expected app. Only if all three pass does ONE
 * transaction replace the token and record the new issuing app on the SAME connection row. Any failure, or an exception anywhere, leaves the
 * connection byte-identical: its old token, its profile and its sync keep working. Nothing is deleted; no account, binding, assignment or
 * metric row is touched, and no new connection row is created.
 */
final class MetaAtomicReconnect
{
    /** Granted permissions the product needs. `ads_management` is only checked as a grant here and is never exercised. */
    public const REQUIRED_PERMISSIONS = ['ads_read'];

    public function __construct(
        private readonly PlatformOAuth $oauth,
        private readonly TokenVault $vault,
        private readonly MetaGraphProbe $graph,
        private readonly AccountAssignment $assignment,
    ) {}

    /**
     * Which app a reconnect uses, and why. Always the PRIMARY app; while the SaaS app is not primary the connection keeps its own.
     *
     * @return array{profile: MetaCredentialProfile, keeps_own_app: bool, reason: string|null}
     */
    public function target(ProviderConnection $connection): array
    {
        $own = MetaCredentialProfile::ofConnection($connection->app_profile ?? null);
        $primary = MetaAppRouting::default();

        if ($primary !== $own) {
            return ['profile' => $primary, 'keeps_own_app' => false, 'reason' => null];
        }

        // Same app: say why the SaaS app is not the one being used, when it is not.
        $reason = $primary === MetaCredentialProfile::Live ? (MetaAppRouting::saasEligibility()['reason'] ?? 'saas_not_primary') : null;

        return ['profile' => $own, 'keeps_own_app' => true, 'reason' => $reason];
    }

    /**
     * Begin a reconnect. Writes nothing to the database.
     *
     * @return array{authorization_url: string, app_profile: string, keeps_own_app: bool, reason: string|null}
     *
     * @throws RuntimeException message is a refusal code
     */
    public function start(ProviderConnection $connection, ?int $userId): array
    {
        if ($connection->provider !== 'meta' || $connection->status === 'revoked') {
            throw new RuntimeException('connection_not_reconnectable');
        }

        $target = $this->target($connection);
        $creds = PlatformCredentials::forMeta($target['profile']);
        if (! $creds->isConfigured()) {
            throw new RuntimeException('target_app_not_configured');
        }
        if ($target['profile'] === MetaCredentialProfile::Saas && ! MetaAppRouting::saasEligibilityFor((string) $connection->tenant_id)['eligible']) {
            throw new RuntimeException('target_app_not_eligible');
        }

        $state = AuthorizationState::issue(
            tenantId: (string) $connection->tenant_id,
            provider: 'meta',
            userId: $userId,
            clientWorkspaceId: $connection->client_workspace_id !== null ? (string) $connection->client_workspace_id : null,
            extra: ['reconnect_connection_id' => (string) $connection->getKey()],
            metaProfile: $target['profile'],
        );

        return [
            'authorization_url' => $this->oauth->authorizationUrl($creds, $state),
            'app_profile' => $target['profile']->value,
            'keeps_own_app' => $target['keeps_own_app'],
            'reason' => $target['reason'],
        ];
    }

    /**
     * Finish a reconnect from a claimed state record. Returns a result code; throws nothing.
     *
     * @param  array<string,mixed>  $record  the claimed, signature-checked state
     * @return array{ok: bool, code: string, connection_id: string|null}
     */
    public function complete(array $record, string $code): array
    {
        $id = (string) ($record['reconnect_connection_id'] ?? '');
        $connectionId = $id === '' ? null : $id;
        $fail = static fn (string $why): array => ['ok' => false, 'code' => $why, 'connection_id' => $connectionId];

        try {
            $connection = $connectionId === null ? null : ProviderConnection::withoutGlobalScopes()->where('provider', 'meta')->find($connectionId);
            if ($connection === null || (string) $connection->tenant_id !== (string) ($record['tenant_id'] ?? '') || $connection->status === 'revoked') {
                return $fail('connection_not_found');
            }

            $profile = MetaCredentialProfile::tryFrom((string) ($record['profile'] ?? ''));
            // The decision made at start must still be the decision now (the primary app may have been switched in between).
            if ($profile === null || $profile !== $this->target($connection)['profile']) {
                return $fail('target_app_changed');
            }
            $app = PlatformCredentials::forMeta($profile);
            if (! $app->isConfigured()) {
                return $fail('target_app_not_configured');
            }

            // 1 — the code is exchanged with the NEW app's credentials. Nothing is stored yet.
            try {
                $tokens = $this->oauth->exchangeCode($app, $code);
            } catch (Throwable) {
                return $fail('token_exchange_failed');
            }

            // 2 — verify, read-only, before any write.
            $failure = $this->verify($connection, $app, $tokens);
            if ($failure !== null) {
                return $fail($failure);
            }

            // 3 — one transaction: token + app on the SAME row. Anything thrown rolls the lot back.
            DB::transaction(function () use ($connection, $profile, $app, $tokens): void {
                $locked = ProviderConnection::withoutGlobalScopes()->whereKey($connection->getKey())->lockForUpdate()->firstOrFail();
                IntegrationCredential::withoutGlobalScopes()->whereKey($locked->credential_id)->lockForUpdate()->firstOrFail();

                $locked->forceFill([
                    'app_profile' => $profile === MetaCredentialProfile::Live ? null : $profile->value,
                    'issuing_app_id' => $app->get('client_id'),
                    'issuing_app_verified_at' => now(),
                ])->save();
                // The vault writes the credential payload and mirrors expiry/status onto the connection; the app is already the new one.
                $this->vault->store($locked, $tokens);
                $locked->forceFill([
                    'scopes' => $tokens->scope === null ? $app->scopes() : explode(' ', $tokens->scope),
                    'last_health_check_at' => now(),
                    'insights_denied_at' => null,
                ])->save();
            });
        } catch (Throwable) {
            return $fail('reconnect_failed');
        }

        return ['ok' => true, 'code' => 'reconnected', 'connection_id' => $connectionId];
    }

    /** @return string|null the failure code, or null when all three checks pass */
    private function verify(ProviderConnection $connection, PlatformCredentials $app, OAuthTokens $tokens): ?string
    {
        try {
            $granted = $this->graph->grantedPermissions($app, $tokens->accessToken);
            if (array_diff($this->requiredPermissions($app), $granted) !== []) {
                return 'permissions_missing';
            }

            $listed = $this->graph->adAccountIds($app, $tokens->accessToken);
            $needed = ExternalAccount::withoutGlobalScopes()
                ->whereIn('id', $this->assignment->activeAccountIdsForConnection((string) $connection->getKey()))
                ->where('account_type', 'ad_account')->pluck('external_id')
                ->map(static fn ($v) => MetaGraphProbe::normalise((string) $v))->all();
            if (array_diff($needed, $listed) !== []) {
                return 'accounts_missing';
            }

            $debug = $this->graph->debugToken($app, $tokens->accessToken);
            if (! $debug['is_valid'] || $debug['app_id'] !== $app->get('client_id')) {
                return 'app_mismatch';
            }
        } catch (RuntimeException $e) {
            return $e->getMessage() === MetaGraphProbe::FAIL_UNREACHABLE ? 'meta_unreachable' : 'verification_failed';
        }

        return null;
    }

    /** @return list<string> */
    private function requiredPermissions(PlatformCredentials $app): array
    {
        return array_values(array_unique([
            ...self::REQUIRED_PERMISSIONS,
            ...array_values(array_intersect($app->scopes(), ['ads_management'])),
        ]));
    }
}
