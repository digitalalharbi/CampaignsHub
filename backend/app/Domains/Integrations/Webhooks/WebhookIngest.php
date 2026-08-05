<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Webhooks;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationWebhookEvent;
use App\Domains\Integrations\Models\ProviderConnection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WEBHOOK-001 — record a verified delivery exactly once, and place it if we can.
 *
 * ## Insert first, work second
 *
 * The row goes in before anything is done with it, and the database's unique index on
 * `(provider, fingerprint)` is what makes a redelivery a no-op. Doing it the other way round — check,
 * then work, then record — leaves a window between the check and the record that is precisely when a
 * provider's retry storm arrives, and two workers both find "not seen yet".
 *
 * A duplicate is a SUCCESS, not an error. Meta retries for 36 hours until it receives a 2xx, so
 * answering an already-processed delivery with a failure keeps it retrying for a day and a half.
 *
 * ## The tenant is derived, never read from the payload
 *
 * A body that could name its own tenant is a body that could name somebody else's. The account id in
 * the payload is looked up against `external_accounts`, and the tenant comes off the row we already
 * hold. A delivery that matches nothing is kept as `unmatched` with a null tenant — evidence of a
 * webhook URL registered against an account nobody here has connected, which is a real
 * misconfiguration and is invisible if the payload is dropped.
 */
final class WebhookIngest
{
    /**
     * @param  array<string,mixed>  $payload
     * @return array{event: IntegrationWebhookEvent, duplicate: bool}
     */
    public function record(string $provider, string $rawBody, array $payload, bool $verified): array
    {
        $definition = ProviderCatalogue::get($provider);
        $externalId = $this->accountIdIn($payload);
        $account = $externalId === null
            ? null
            : ExternalAccount::withoutGlobalScopes()->where('external_id', $externalId)
                ->where('provider', $definition->key)->first();

        $connection = $account === null
            ? null
            : ProviderConnection::withoutGlobalScopes()->find($account->provider_connection_id);

        $attributes = [
            'provider' => $definition->key,
            'kind' => $definition->kind->value,
            'topic' => $this->topicIn($payload),
            'external_event_id' => $this->eventIdIn($payload),
            'fingerprint' => $this->fingerprint($payload, $rawBody),
            'tenant_id' => $account?->tenant_id ?? $connection?->tenant_id,
            'provider_connection_id' => $connection?->getKey(),
            'external_account_id' => $account?->getKey(),
            'payload' => $payload,
            'signature_verified' => $verified,
            'status' => $account === null ? 'unmatched' : 'received',
            'received_at' => Carbon::now(),
        ];

        try {
            /*
             * Wrapped so the failure is scoped to a SAVEPOINT.
             *
             * Postgres aborts the entire transaction a failed statement was in, so catching the
             * violation and then querying leaves every subsequent statement refused with «current
             * transaction is aborted». Standalone that never bites, because the insert is its own
             * transaction — but the moment this is called inside one (a test's `RefreshDatabase`, or
             * any future consumer that wraps ingestion in a transaction) the recovery path is what
             * breaks. `DB::transaction` opens a savepoint when nested, so the rollback undoes the
             * failed insert and nothing else.
             */
            $event = DB::transaction(static fn () => IntegrationWebhookEvent::create($attributes));
        } catch (UniqueConstraintViolationException) {
            // Already recorded. The provider is retrying because our previous 2xx was lost, or two
            // deliveries raced; either way there is nothing left to do and this is a success.
            $existing = IntegrationWebhookEvent::query()
                ->where('provider', $attributes['provider'])
                ->where('fingerprint', $attributes['fingerprint'])
                ->firstOrFail();

            return ['event' => $existing, 'duplicate' => true];
        }

        return ['event' => $event, 'duplicate' => false];
    }

    /**
     * What makes two deliveries the same delivery.
     *
     * The provider's own event id when there is one — that is what a retry preserves. A hash of the
     * raw body when there is not, which de-duplicates an identical redelivery and correctly treats a
     * genuinely different event as different.
     */
    private function fingerprint(array $payload, string $rawBody): string
    {
        $id = $this->eventIdIn($payload);

        return $id !== null ? hash('sha256', $id) : hash('sha256', $rawBody);
    }

    /** @param array<string,mixed> $payload */
    private function eventIdIn(array $payload): ?string
    {
        foreach (['event_id', 'id', 'merchant_event_id', 'delivery_id'] as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_int($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $payload */
    private function topicIn(array $payload): ?string
    {
        foreach (['event', 'topic', 'object', 'type'] as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 120);
            }
        }

        return null;
    }

    /**
     * The provider's own account/store identifier, wherever that provider puts it.
     *
     * Meta wraps its changes in `entry[].id`; Salla and Zid name the merchant at the top level. Only
     * these known shapes are read — a recursive search for anything that looks like an id would let a
     * crafted payload point a delivery at somebody else's account.
     *
     * @param  array<string,mixed>  $payload
     */
    private function accountIdIn(array $payload): ?string
    {
        foreach (['account_id', 'ad_account_id', 'merchant', 'store_id'] as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) || is_int($value)) {
                return (string) $value;
            }
        }

        $entry = $payload['entry'][0]['id'] ?? null;

        return is_string($entry) || is_int($entry) ? (string) $entry : null;
    }
}
