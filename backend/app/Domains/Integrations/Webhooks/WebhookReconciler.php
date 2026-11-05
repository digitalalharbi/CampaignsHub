<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Webhooks;

use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Providers\ApiCommerceConnector;
use App\Domains\Commerce\Registry\CommerceConnectorRegistry;
use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\ProviderKind;
use App\Domains\Integrations\Catalogue\WebhookSupport;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationWebhookEvent;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\AccountAssignment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * INTEG-WEBHOOK-ENGINE-001 — the periodic proof that webhooks and the store agree.
 *
 * Webhooks are the fast path and are allowed to be lossy; the hourly sweep is what makes them safe. This
 * is the third leg: for each BOUND store of a provider that delivers webhooks, read its recent orders from
 * the provider and compare them with what we hold, and record every difference as a `gap` in the inbox —
 * together with whether any webhook about that order ever arrived, which is what tells «the provider never
 * sent it» from «it arrived and was not applied».
 *
 * ## Read-only toward the provider, and it repairs nothing
 *
 * One orders read per store per run, bounded in stores and in days. It writes only the gap rows (and not
 * even those under `--dry-run`); fixing a gap is the sweep's job, and a reconciler that also wrote orders
 * would be a second path by which numbers arrive. Providers without webhooks are not touched: their
 * polling IS their only path, so there is nothing for this to compare.
 *
 * ## It asks the store the same question the sync does
 *
 * The token must reach THIS store before its orders are believed (ACCOUNT-SCOPE-ISOLATION-001), or two
 * stores of one merchant would be reconciled against each other's orders.
 */
final class WebhookReconciler
{
    public function __construct(
        private readonly CommerceConnectorRegistry $registry,
        private readonly AccountAssignment $assignment,
    ) {}

    /**
     * @return array{stores: int, orders_read: int, gaps: list<array<string,mixed>>, skipped: list<string>, dry_run: bool}
     */
    public function run(int $days, int $maxStores, bool $dryRun, ?string $provider = null): array
    {
        $days = max(1, min(14, $days));
        $maxStores = max(1, $maxStores);
        $grace = Carbon::now()->subMinutes((int) config('integrations.webhooks.reconcile.grace_minutes', 90));

        $providers = collect(ProviderCatalogue::all())
            ->filter(fn ($d) => $d->kind === ProviderKind::Commerce && $d->webhooks !== WebhookSupport::PollingOnly)
            ->map(fn ($d) => $d->key)
            ->when($provider, fn ($c, $p) => $c->filter(fn ($k) => $k === $p))
            ->values()
            ->all();

        $stores = ExternalAccount::withoutGlobalScopes()
            ->whereIn('provider', $providers)
            ->where('account_type', 'store')
            ->orderBy('id')
            ->get()
            ->filter(fn (ExternalAccount $s): bool => $this->assignment->isActivelyAssigned($s))
            ->take($maxStores);

        $summary = ['stores' => 0, 'orders_read' => 0, 'gaps' => [], 'skipped' => [], 'dry_run' => $dryRun];

        foreach ($stores as $store) {
            $orders = $this->read($store, $days, $summary);

            if ($orders === null) {
                continue;
            }

            $summary['stores']++;
            $summary['orders_read'] += count($orders);

            foreach ($orders as $order) {
                $gap = $this->gapFor($store, $order, $grace);

                if ($gap === null) {
                    continue;
                }

                $gap['inbox_seen'] = $this->inboxSeen($store, $gap['order_id']);
                $summary['gaps'][] = $gap;

                if (! $dryRun) {
                    $this->record($store, $gap);
                }
            }
        }

        return $summary;
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return list<array<string,mixed>>|null
     */
    private function read(ExternalAccount $store, int $days, array &$summary): ?array
    {
        $connector = $this->registry->get($store->provider);

        if ($connector instanceof ApiCommerceConnector) {
            $connection = ProviderConnection::withoutGlobalScopes()->find($store->provider_connection_id);

            if ($connection === null) {
                $summary['skipped'][] = $store->getKey().': no connection';

                return null;
            }

            $connector = $connector->withConnection($connection);
        }

        if ($connector === null || $connector->status() === ConnectorStatus::AwaitingCredentials) {
            $summary['skipped'][] = $store->getKey().': no credentials';

            return null;
        }

        try {
            $reached = array_map(static fn (array $s): string => (string) ($s['external_id'] ?? ''), $connector->fetchStores());

            if (! in_array((string) $store->external_id, $reached, true)) {
                $summary['skipped'][] = $store->getKey().': the authorisation reaches a different store';

                return null;
            }

            $result = $connector->syncOrders(
                (string) $store->external_id,
                Carbon::now()->subDays($days)->toDateString(),
                Carbon::now()->toDateString(),
            );
        } catch (Throwable $e) {
            $summary['skipped'][] = $store->getKey().': '.class_basename($e);

            return null;
        }

        if (! $result->success) {
            $summary['skipped'][] = $store->getKey().': the provider reported a failure';

            return null;
        }

        return array_values($result->records);
    }

    /**
     * @param  array<string,mixed>  $order
     * @return array{order_id: string, gap: string, provider_status: string, local_status: ?string}|null
     */
    private function gapFor(ExternalAccount $store, array $order, Carbon $grace): ?array
    {
        $id = (string) ($order['external_id'] ?? '');

        if ($id === '') {
            return null;
        }

        $providerStatus = (string) ($order['status'] ?? 'unknown');
        $local = CommerceOrder::withoutGlobalScopes()
            ->where('external_account_id', $store->getKey())
            ->where('external_id', $id)
            ->first();

        if ($local === null) {
            $placed = WebhookEventFacts::parse($order['placed_at'] ?? null);

            // An order placed inside the grace window is the sweep's to pick up: not a gap yet.
            if ($placed !== null && $placed->greaterThan($grace)) {
                return null;
            }

            return ['order_id' => $id, 'gap' => 'missing_local', 'provider_status' => $providerStatus, 'local_status' => null];
        }

        $synced = $local->last_synced_at === null ? null : Carbon::parse($local->last_synced_at);

        if ((string) $local->status !== $providerStatus && ($synced === null || $synced->lessThan($grace))) {
            return ['order_id' => $id, 'gap' => 'status_mismatch', 'provider_status' => $providerStatus, 'local_status' => (string) $local->status];
        }

        return null;
    }

    private function inboxSeen(ExternalAccount $store, string $orderId): bool
    {
        return IntegrationWebhookEvent::query()
            ->where('provider', $store->provider)
            ->where('source', 'webhook')
            ->where('entity_key', $store->external_id.':order:'.$orderId)
            ->exists();
    }

    /** @param array<string,mixed> $gap */
    private function record(ExternalAccount $store, array $gap): void
    {
        $fingerprint = hash('sha256', implode('|', ['gap', $store->getKey(), $gap['order_id'], $gap['gap'], $gap['provider_status']]));

        $existing = IntegrationWebhookEvent::query()->where('provider', $store->provider)->where('fingerprint', $fingerprint)->first();

        if ($existing !== null) {
            $existing->forceFill(['duplicate_count' => $existing->duplicate_count + 1, 'last_duplicate_at' => Carbon::now()])->save();

            return;
        }

        try {
            DB::transaction(static fn () => IntegrationWebhookEvent::create([
                'provider' => $store->provider,
                'kind' => ProviderKind::Commerce->value,
                'topic' => 'reconciliation.'.$gap['gap'],
                'fingerprint' => $fingerprint,
                'tenant_id' => $store->tenant_id,
                'provider_connection_id' => $store->provider_connection_id,
                'external_account_id' => $store->getKey(),
                // Ids and statuses only: no customer, amount or address leaves the provider read into the inbox.
                'payload' => [
                    'order_id' => $gap['order_id'], 'gap' => $gap['gap'],
                    'provider_status' => $gap['provider_status'], 'local_status' => $gap['local_status'],
                    'inbox_seen' => $gap['inbox_seen'],
                ],
                'signature_verified' => false,
                'status' => IntegrationWebhookEvent::GAP,
                'verdict' => 'gap',
                'source' => 'reconciliation',
                'entity_key' => $store->external_id.':order:'.$gap['order_id'],
                'received_at' => Carbon::now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            // a concurrent run recorded the same gap
        }
    }
}
