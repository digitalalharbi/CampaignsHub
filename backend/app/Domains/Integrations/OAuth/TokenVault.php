<?php

declare(strict_types=1);

namespace App\Domains\Integrations\OAuth;

use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * INTEG-OAUTH-001 — where platform tokens live, and the only place they are read.
 *
 * ## The rule this class exists to enforce
 *
 * **Nothing calls a platform with a token it has not asked the vault for.** `fresh()` is the only
 * public way to obtain one, and it refreshes before handing anything back. A connector that read the
 * stored token directly would work perfectly for an hour and then fail for every customer at once,
 * which is precisely the failure mode that makes token expiry so expensive to diagnose.
 *
 * ## Storage
 *
 * The token set is JSON inside `integration_credentials.encrypted_payload`, which is cast `encrypted`
 * — so it is ciphertext at rest, hidden from every API resource, and never logged. The CONNECTION row
 * carries only the derived facts an operator needs to see: when the token expires, when it was last
 * checked, and the last error. Nothing secret is duplicated onto it.
 *
 * ## A failed refresh is a connection state, not an exception nobody catches
 *
 * When a refresh fails the connection is stamped `error` with the reason before the throw, so the
 * integrations page shows «انتهت الصلاحية — أعد الربط» rather than a silent zero. A token cannot be
 * refreshed for exactly one interesting reason — the customer revoked it — and that has to be visible.
 */
final class TokenVault
{
    public function __construct(private readonly PlatformOAuth $oauth) {}

    /**
     * The token to actually call the platform with.
     *
     * @throws RuntimeException when there is no usable token and none can be obtained
     */
    public function fresh(ProviderConnection $connection): OAuthTokens
    {
        $creds = PlatformCredentials::for($connection->provider);
        $tokens = $this->stored($connection);

        if (! $tokens->isExpired()) {
            return $tokens;
        }

        try {
            $refreshed = $this->oauth->refresh($creds, $tokens);
        } catch (Throwable $e) {
            $this->markError($connection, $e->getMessage());

            throw new RuntimeException(
                $creds->label().' could not refresh its access token: '.$e->getMessage(),
                previous: $e,
            );
        }

        $this->store($connection, $refreshed);

        return $refreshed;
    }

    /** The token set as stored, with no refresh. Only the refresh command and tests want this. */
    public function stored(ProviderConnection $connection): OAuthTokens
    {
        $credential = IntegrationCredential::withoutGlobalScopes()->find($connection->credential_id);

        if ($credential === null) {
            throw new RuntimeException('The connection has no stored credential.');
        }

        /** @var array<string,mixed>|null $decoded */
        $decoded = json_decode($credential->revealPayload(), true);

        if (! is_array($decoded) || ($decoded['access_token'] ?? '') === '') {
            throw new RuntimeException('The stored credential holds no access token.');
        }

        return OAuthTokens::fromStorage($decoded);
    }

    /** Write a token set, and mirror only its non-secret facts onto the connection. */
    public function store(ProviderConnection $connection, OAuthTokens $tokens): void
    {
        $credential = IntegrationCredential::withoutGlobalScopes()->findOrFail($connection->credential_id);

        $credential->forceFill([
            'encrypted_payload' => json_encode($tokens->toStorage(), JSON_THROW_ON_ERROR),
            'status' => 'active',
            'expires_at' => $tokens->expiresAt,
            'last_rotated_at' => Carbon::now(),
        ])->save();

        $connection->forceFill([
            'status' => 'connected',
            'token_expires_at' => $tokens->expiresAt,
            'last_error' => null,
        ])->save();
    }

    /**
     * Open — or RE-open — a connection from a completed authorisation.
     *
     * The credential and the connection are written together because either one alone is unusable: a
     * credential nothing points at is an orphaned secret, and a connection with no credential is a row
     * that claims a link it cannot exercise.
     *
     * ## Re-authorising is the same connection, not a second one
     *
     * Found by the acceptance test, and worth stating plainly because the first version got it wrong:
     * a customer who authorises Meta again — after a password change, a revoked token, or simply
     * pressing connect twice — must land on the SAME `ProviderConnection`. Minting a new one looks
     * harmless until you follow it: `external_accounts` is unique per CONNECTION, so every ad account
     * is discovered a second time, and `daily_metrics` hangs off the account. Two copies of one
     * account means every figure that account reports is counted twice — spend, revenue, conversions —
     * across the dashboard, the reports and the alerts, with nothing anywhere saying why.
     *
     * One connection per (tenant, workspace, platform), therefore, re-credentialed in place. A
     * customer who authorises a DIFFERENT identity on the same platform replaces the tokens, which is
     * what they asked for; the accounts the old identity could see stop being refreshed rather than
     * silently doubling the ones the new identity can.
     */
    public function open(
        string $tenantId,
        string $provider,
        OAuthTokens $tokens,
        string $connectionName,
        ?string $clientWorkspaceId = null,
        ?int $createdBy = null,
        ?string $externalOwnerId = null,
    ): ProviderConnection {
        $creds = PlatformCredentials::for($provider);

        $existing = ProviderConnection::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('provider', $creds->platform)
            ->when(
                $clientWorkspaceId === null,
                fn ($q) => $q->whereNull('client_workspace_id'),
                fn ($q) => $q->where('client_workspace_id', $clientWorkspaceId),
            )
            ->first();

        if ($existing !== null) {
            $this->store($existing, $tokens);

            $existing->forceFill(array_filter([
                'connection_name' => $connectionName,
                'external_owner_id' => $externalOwnerId,
                'scopes' => $tokens->scope === null ? $creds->scopes() : explode(' ', $tokens->scope),
                'last_health_check_at' => Carbon::now(),
            ], static fn ($v) => $v !== null))->save();

            return $existing;
        }

        $credential = new IntegrationCredential;
        $credential->forceFill([
            'tenant_id' => $tenantId,
            'client_workspace_id' => $clientWorkspaceId,
            'provider' => $creds->platform,
            'credential_scope' => $clientWorkspaceId === null ? 'tenant_shared' : 'workspace_shared',
            'credential_type' => 'oauth2',
            'encrypted_payload' => json_encode($tokens->toStorage(), JSON_THROW_ON_ERROR),
            'status' => 'active',
            'expires_at' => $tokens->expiresAt,
            'last_rotated_at' => Carbon::now(),
            'created_by' => $createdBy,
        ])->save();

        $connection = new ProviderConnection;
        $connection->forceFill([
            'tenant_id' => $tenantId,
            'client_workspace_id' => $clientWorkspaceId,
            'credential_id' => $credential->getKey(),
            'provider' => $creds->platform,
            'connection_name' => $connectionName,
            'scope' => $clientWorkspaceId === null ? 'tenant_shared' : 'workspace_shared',
            'external_owner_id' => $externalOwnerId,
            'scopes' => $tokens->scope === null ? $creds->scopes() : explode(' ', $tokens->scope),
            'status' => 'connected',
            'token_expires_at' => $tokens->expiresAt,
            'last_health_check_at' => Carbon::now(),
            'created_by' => $createdBy,
        ])->save();

        return $connection;
    }

    public function markError(ProviderConnection $connection, string $reason): void
    {
        $connection->forceFill([
            'status' => 'error',
            // The column is a string, and a provider's error body can be a page long.
            'last_error' => mb_substr($reason, 0, 250),
            'last_health_check_at' => Carbon::now(),
        ])->save();
    }
}
