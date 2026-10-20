<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Install;

use App\Domains\Commerce\Models\PendingStoreInstall;
use App\Domains\Commerce\Services\StoreDiscovery;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\PlatformOAuth;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Support\PlatformHttp;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * SALLA-INSTALL-001 — a store installed from Salla's side.
 *
 * Real merchants install from the Salla App Store or the partner portal, not from a button in
 * CampaignsHub. That install reaches the OAuth callback with Salla's `code` and none of our `state`:
 * there is no tenant, user or workspace to file it under, and refusing it as «invalid state» refused
 * every merchant who installed the way Salla intends.
 *
 * So the authorisation is kept and the ownership is asked for. The code is exchanged (Salla issued
 * it for this app, which the exchange itself proves), the store is read once for its name, and the
 * tokens are kept encrypted in a pending install keyed by the merchant. The merchant is handed a
 * one-time claim code; a signed-in user who presents it attaches the store to their workspace, and
 * only then does it become a connection. An unclaimed install expires.
 *
 * Salla also tells us about installs by webhook: `app.store.authorize` carries fresh tokens (it is
 * how «easy mode» delivers them, and how a reinstall renews them), `app.uninstalled` withdraws the
 * app. Those arrive through the signature-verified webhook endpoint.
 */
final class SallaInstalls
{
    private const PROVIDER = 'salla';

    private const CLAIM_DAYS = 7;

    public function __construct(
        private readonly PlatformOAuth $oauth,
        private readonly TokenVault $vault,
        private readonly StoreDiscovery $discovery,
    ) {}

    /**
     * A callback with Salla's code and none of our state: exchange it and keep it for claiming.
     *
     * @return string the one-time claim code to hand the merchant
     */
    public function fromCode(string $code): string
    {
        $tokens = $this->oauth->exchangeCode(PlatformCredentials::for(self::PROVIDER), $code);

        return $this->keep($tokens);
    }

    /**
     * `app.store.authorize` — Salla hands over fresh tokens for a merchant.
     *
     * A merchant whose store is already connected has its tokens renewed in place (a reinstall, a
     * re-authorisation); otherwise the tokens wait as a pending install for the merchant to claim.
     *
     * @param  array<string,mixed>  $payload
     */
    public function onAuthorize(array $payload): void
    {
        $merchant = $this->merchantOf($payload);
        $data = (array) ($payload['data'] ?? []);
        $access = is_string($data['access_token'] ?? null) ? $data['access_token'] : '';
        if ($merchant === null || $access === '') {
            return;
        }

        $tokens = new OAuthTokens(
            $access,
            is_string($data['refresh_token'] ?? null) ? $data['refresh_token'] : null,
            is_numeric($data['expires'] ?? null) ? Carbon::createFromTimestamp((int) $data['expires']) : null,
            is_string($data['scope'] ?? null) ? $data['scope'] : null,
        );

        $connection = $this->connectionFor($merchant);
        if ($connection !== null) {
            $connection->forceFill(['uninstalled_at' => null])->save();
            $this->vault->open(
                tenantId: (string) $connection->tenant_id,
                provider: self::PROVIDER,
                tokens: $tokens,
                connectionName: (string) $connection->connection_name,
                clientWorkspaceId: $connection->client_workspace_id === null ? null : (string) $connection->client_workspace_id,
            );

            return;
        }

        $this->keep($tokens, $merchant);
    }

    /**
     * `app.installed` — Salla says the app now exists on a store.
     *
     * The tokens arrive by the authorisation (`app.store.authorize`, or the browser callback), so this carries
     * nothing to act on; a reinstall of a store this product knows clears its uninstalled mark so the retention
     * clock stops.
     *
     * @param  array<string,mixed>  $payload
     */
    public function onInstalled(array $payload): void
    {
        $merchant = $this->merchantOf($payload);
        $this->connectionFor((string) $merchant)?->forceFill(['uninstalled_at' => null])->save();
    }

    /**
     * `app.uninstalled` — the merchant removed the app: the connection stops, its tokens are revoked, the store
     * stops syncing, and the data is kept for the retention period (`commerce:purge-uninstalled`, 30 days).
     *
     * @param  array<string,mixed>  $payload
     */
    public function onUninstall(array $payload): void
    {
        $merchant = $this->merchantOf($payload);
        if ($merchant === null) {
            return;
        }

        $connection = $this->connectionFor($merchant);
        if ($connection !== null) {
            $connection->forceFill([
                'status' => 'revoked',
                'last_error' => 'The app was uninstalled from the Salla store.',
                'uninstalled_at' => Carbon::now(),
            ])->save();

            // The tokens stop working at Salla; keeping them here would be keeping a dead key. What stays is the
            // connection row and the synced data, for the retention period.
            $credential = IntegrationCredential::withoutGlobalScopes()->find($connection->credential_id);
            $credential?->forceFill([
                'encrypted_payload' => json_encode(['access_token' => '', 'refresh_token' => null, 'expires_at' => null, 'scope' => null, 'raw' => []]),
                'status' => 'revoked',
                'expires_at' => null,
            ])->save();
        }

        PendingStoreInstall::query()
            ->where('provider', self::PROVIDER)
            ->where('merchant_id', $merchant)
            ->whereNull('claimed_at')
            ->delete();
    }

    /**
     * `app.subscription.started | renewed | expired | canceled` — the merchant's plan for THIS app, recorded on
     * the tenant that owns the store.
     *
     * Salla's payload for these events is not documented in the pages available when this was written (only the
     * event names and the merchant id are), so only those are read. The status is recorded in the tenant's
     * settings and shown as the plan label; it does not cut access, because what an expired Salla subscription
     * should entitle is a product decision and not something to infer from an event name.
     *
     * @param  array<string,mixed>  $payload
     */
    public function onSubscription(array $payload, string $event): void
    {
        $merchant = $this->merchantOf($payload);
        $connection = $merchant === null ? null : $this->connectionFor($merchant);
        $tenant = $connection === null ? null : Tenant::withoutGlobalScopes()->find($connection->tenant_id);
        if ($tenant === null) {
            return;
        }

        $status = match ($event) {
            'app.subscription.started', 'app.subscription.renewed' => 'active',
            'app.subscription.expired' => 'expired',
            'app.subscription.canceled' => 'canceled',
            default => null,
        };
        if ($status === null) {
            return;
        }

        $settings = (array) ($tenant->settings ?? []);
        $settings['salla_subscription'] = ['status' => $status, 'event' => $event, 'at' => Carbon::now()->toIso8601String()];
        $tenant->forceFill([
            'settings' => $settings,
            'subscription_plan' => $status === 'active' ? 'salla-subscribed' : 'salla-'.$status,
        ])->save();
    }

    /** The connection that already owns this Salla merchant, if any — a store belongs to exactly one tenant. */
    public function connectionForMerchant(string $merchant): ?ProviderConnection
    {
        return $this->connectionFor($merchant);
    }

    /** New tokens for a connection that already exists (a reinstall or a re-entry), and the retention clock cleared. */
    public function renew(ProviderConnection $connection, OAuthTokens $tokens): void
    {
        $connection->forceFill(['uninstalled_at' => null])->save();
        $this->vault->open(
            tenantId: (string) $connection->tenant_id,
            provider: self::PROVIDER,
            tokens: $tokens,
            connectionName: (string) $connection->connection_name,
            clientWorkspaceId: $connection->client_workspace_id === null ? null : (string) $connection->client_workspace_id,
        );
    }

    /** What a claim page may show before anybody signs in: the store, never the tokens. */
    public function preview(string $claimCode): ?PendingStoreInstall
    {
        return $this->pending($claimCode);
    }

    /**
     * A signed-in user attaches the installed store to their workspace.
     *
     * @return array{connection_id: string, stores: int}
     */
    public function claim(string $claimCode, string $tenantId, ?int $userId, ?string $workspaceId = null): array
    {
        $install = $this->pending($claimCode);
        if ($install === null) {
            throw new RuntimeException('This install link has expired or was already used.');
        }

        app(TenantContext::class)->setTenantId($tenantId);

        $t = $install->tokens;
        $connection = $this->vault->open(
            tenantId: $tenantId,
            provider: self::PROVIDER,
            tokens: new OAuthTokens(
                (string) $t['access_token'],
                isset($t['refresh_token']) ? (string) $t['refresh_token'] : null,
                isset($t['expires_at']) ? Carbon::parse((string) $t['expires_at']) : null,
                isset($t['scope']) ? (string) $t['scope'] : null,
            ),
            connectionName: PlatformCredentials::for(self::PROVIDER)->label(),
            clientWorkspaceId: $workspaceId,
            createdBy: $userId,
        );

        $stores = $this->discovery->discover($connection);

        $install->forceFill([
            'claimed_at' => Carbon::now(),
            'claimed_tenant_id' => $tenantId,
            'provider_connection_id' => (string) $connection->getKey(),
            'claim_code_hash' => null,
            'tokens' => [],
        ])->save();

        return ['connection_id' => (string) $connection->getKey(), 'stores' => $stores];
    }

    /** Keep tokens as a pending install; returns a fresh one-time claim code. */
    private function keep(OAuthTokens $tokens, ?string $merchant = null): string
    {
        $store = $this->storeInfo($tokens);
        $merchant ??= isset($store['id']) ? (string) $store['id'] : null;
        if ($merchant === null) {
            throw new RuntimeException('Salla did not say which store this authorisation belongs to.');
        }

        $code = Str::random(48);

        PendingStoreInstall::query()->updateOrCreate(
            ['provider' => self::PROVIDER, 'merchant_id' => $merchant, 'claimed_at' => null],
            [
                'store_name' => is_string($store['name'] ?? null) && trim($store['name']) !== '' ? $store['name'] : null,
                'store_domain' => is_string($store['domain'] ?? null) ? $store['domain'] : null,
                'store_currency' => is_string($store['currency'] ?? null) ? $store['currency'] : null,
                'tokens' => array_filter([
                    'access_token' => $tokens->accessToken,
                    'refresh_token' => $tokens->refreshToken,
                    'expires_at' => $tokens->expiresAt?->toIso8601String(),
                    'scope' => $tokens->scope,
                ], static fn ($v) => $v !== null),
                'claim_code_hash' => hash('sha256', $code),
                'expires_at' => Carbon::now()->addDays(self::CLAIM_DAYS),
            ],
        );

        return $code;
    }

    /** @return array<string,mixed> */
    private function storeInfo(OAuthTokens $tokens): array
    {
        $response = PlatformHttp::client(self::PROVIDER)
            ->withToken($tokens->accessToken)
            ->get(rtrim((string) config('commerce_platforms.platforms.salla.api_base'), '/').'/store/info');

        return $response->successful() ? (array) ($response->json('data') ?? []) : [];
    }

    private function pending(string $claimCode): ?PendingStoreInstall
    {
        if ($claimCode === '') {
            return null;
        }

        return PendingStoreInstall::query()
            ->where('claim_code_hash', hash('sha256', $claimCode))
            ->whereNull('claimed_at')
            ->where('expires_at', '>', Carbon::now())
            ->first();
    }

    private function connectionFor(string $merchant): ?ProviderConnection
    {
        $store = ExternalAccount::withoutGlobalScopes()
            ->where('provider', self::PROVIDER)
            ->where('account_type', 'store')
            ->where('external_id', $merchant)
            ->whereNotNull('provider_connection_id')
            ->latest('updated_at')
            ->first();

        return $store === null ? null : ProviderConnection::withoutGlobalScopes()->find($store->provider_connection_id);
    }

    /** @param  array<string,mixed>  $payload */
    private function merchantOf(array $payload): ?string
    {
        $merchant = $payload['merchant'] ?? null;

        return is_scalar($merchant) && (string) $merchant !== '' ? (string) $merchant : null;
    }
}
