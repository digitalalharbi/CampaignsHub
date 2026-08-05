<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Providers;

use App\Domains\Commerce\Contracts\CommerceConnector;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Support\PlatformHttp;
use App\Domains\Integrations\ValueObjects\HealthResult;
use App\Domains\Integrations\ValueObjects\SyncResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * COMMERCE-001 — the shared half of a store adapter, kept as thin as it honestly can be.
 *
 * It mirrors `ApiAdvertisingConnector` in the three things that are genuinely the same question — an
 * unconfigured provider refuses without calling out, a bound connection is a CLONE so one tenant's
 * tokens cannot leak into another's job, and every response is recorded raw by construction — and
 * shares nothing else, because nothing else IS the same.
 *
 * ## Three states, and they are not interchangeable
 *
 * 1. **Not configured** — nobody has registered an app with the provider on this install.
 * 2. **Configured, not bound** — the provider can be reached, but no merchant has authorised us.
 * 3. **Configured and bound** — a real connection with real tokens; calls go out.
 *
 * Collapsing 1 and 2 is what makes an integrations page lie in both directions at once.
 */
abstract class ApiCommerceConnector implements CommerceConnector
{
    protected ?ProviderConnection $connection = null;

    /**
     * Every body this connector has been handed since the last time somebody took them.
     *
     * Collected in the base class rather than in each adapter so it is impossible to add a provider
     * and forget: `read()` is the only sanctioned way to look at a response (INTEG-RAW-001).
     *
     * @var list<array<string,mixed>>
     */
    protected array $rawResponses = [];

    abstract protected function platform(): string;

    public function key(): string
    {
        return $this->platform();
    }

    public function label(): string
    {
        return $this->credentials()->label();
    }

    public function status(): ConnectorStatus
    {
        if (! $this->credentials()->isConfigured()) {
            return ConnectorStatus::AwaitingCredentials;
        }

        if ($this->connection === null) {
            return ConnectorStatus::Disconnected;
        }

        return match ($this->connection->status) {
            'connected' => ConnectorStatus::Connected,
            'error' => ConnectorStatus::Error,
            default => ConnectorStatus::Disconnected,
        };
    }

    /** A CLONE, because the registry hands out one instance per provider for the whole process. */
    public function withConnection(ProviderConnection $connection): static
    {
        if ($connection->provider !== $this->platform()) {
            throw new RuntimeException("A {$connection->provider} connection cannot drive the {$this->key()} connector.");
        }

        $bound = clone $this;
        $bound->connection = $connection;

        return $bound;
    }

    /** A health check is a real, cheap call — listing the store — not a ping. */
    public function healthCheck(): HealthResult
    {
        if (! $this->credentials()->isConfigured()) {
            return HealthResult::down($this->label().' is awaiting credentials.');
        }

        if ($this->connection === null) {
            return HealthResult::down($this->label().' is configured but no merchant has authorised it for this workspace yet.');
        }

        try {
            $stores = $this->fetchStores();
        } catch (Throwable $e) {
            return HealthResult::down($e->getMessage());
        }

        return HealthResult::ok(count($stores).' store(s) reachable.');
    }

    // ── The four syncs, each wrapped in the same honest refusal ────────────────────────────────

    public function syncProducts(string $storeId): SyncResult
    {
        return $this->guarded(fn () => $this->fetchProducts($storeId));
    }

    public function syncOrders(string $storeId, string $from, string $to): SyncResult
    {
        return $this->guarded(fn () => $this->fetchOrders($storeId, $from, $to));
    }

    public function syncCustomers(string $storeId, string $from, string $to): SyncResult
    {
        return $this->guarded(fn () => $this->fetchCustomers($storeId, $from, $to));
    }

    public function syncAbandonedCarts(string $storeId, string $from, string $to): SyncResult
    {
        return $this->guarded(fn () => $this->fetchAbandonedCarts($storeId, $from, $to));
    }

    /** @return list<array<string,mixed>> */
    abstract protected function fetchProducts(string $storeId): array;

    /** @return list<array<string,mixed>> */
    abstract protected function fetchOrders(string $storeId, string $from, string $to): array;

    /** @return list<array<string,mixed>> */
    abstract protected function fetchCustomers(string $storeId, string $from, string $to): array;

    /** @return list<array<string,mixed>> */
    abstract protected function fetchAbandonedCarts(string $storeId, string $from, string $to): array;

    // ── Shared plumbing ───────────────────────────────────────────────────────────────────────

    /** @param callable():list<array<string,mixed>> $fetch */
    protected function guarded(callable $fetch): SyncResult
    {
        $refusal = $this->refusal();

        if ($refusal !== null) {
            return $refusal;
        }

        try {
            return SyncResult::of($fetch());
        } catch (Throwable $e) {
            return SyncResult::failed($e->getMessage());
        }
    }

    protected function credentials(): PlatformCredentials
    {
        return PlatformCredentials::for($this->platform());
    }

    protected function tokens(): OAuthTokens
    {
        if ($this->connection === null) {
            throw new RuntimeException($this->label().' has no connection bound.');
        }

        return app(TokenVault::class)->fresh($this->connection);
    }

    /**
     * The authenticated client for this provider.
     *
     * Zid needs BOTH of the values its token exchange returned — the bearer and the manager token —
     * on every single call. Putting that in one place is what stops it from being right in three
     * methods and missing from the fourth, which is a bug that only shows on the endpoint nobody
     * tested.
     */
    protected function api(): PendingRequest
    {
        $tokens = $this->tokens();
        $request = PlatformHttp::client($this->platform())->withToken($tokens->accessToken);

        if ($this->platform() === 'zid') {
            $manager = $tokens->raw['authorization'] ?? null;

            if (! is_string($manager) || $manager === '') {
                throw new RuntimeException($this->label().' has no manager token stored; the merchant must authorise again.');
            }

            $request = $request->withHeaders([
                'X-Manager-Token' => $manager,
                // Zid answers in the store's own language unless told otherwise; Arabic names in a
                // product table are the point, so the store's locale is asked for explicitly.
                'Accept-Language' => 'ar',
            ]);
        }

        return $request;
    }

    protected function url(string $path): string
    {
        return $this->credentials()->apiBase().'/'.ltrim($path, '/');
    }

    /**
     * The one honest refusal, shared by all four syncs.
     *
     * It keeps «we hold no keys», «no merchant has authorised us» and «the last attempt failed» apart,
     * because those send the reader to three different places and one generic «فشلت المزامنة» sends
     * them to the wrong one.
     */
    private function refusal(): ?SyncResult
    {
        return match ($this->status()) {
            ConnectorStatus::AwaitingCredentials => SyncResult::failed(
                $this->label().' is awaiting credentials — no store can be read.',
            ),
            ConnectorStatus::Disconnected => SyncResult::failed(
                $this->label().' is configured but no merchant has authorised it for this workspace.',
            ),
            ConnectorStatus::Error => SyncResult::failed(
                $this->connection?->last_error ?? ($this->label().' is in an error state.'),
            ),
            ConnectorStatus::Connected => null,
        };
    }

    /**
     * Read a provider answer, or fail with the provider's own words.
     *
     * @return array<string,mixed>
     */
    protected function read(Response $response, string $what): array
    {
        if (! PlatformHttp::succeeded($response)) {
            throw new RuntimeException($this->label()." could not return {$what}: ".PlatformHttp::reason($response));
        }

        /** @var array<string,mixed> $body */
        $body = $response->json() ?? [];

        $this->rawResponses[] = $body;

        return $body;
    }

    /**
     * Take the raw bodies collected so far, and forget them.
     *
     * @return list<array<string,mixed>>
     */
    public function takeRawResponses(): array
    {
        $taken = $this->rawResponses;
        $this->rawResponses = [];

        return $taken;
    }
}
