<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * WEBHOOK-001 — one event a provider pushed us.
 *
 * Deliberately NOT `BelongsToTenant`. The global scope would apply on the INSERT path, where there is
 * no tenant yet — the tenant is derived from the connection the payload resolves to, and a delivery
 * that resolves to nothing must still be recorded. Every READ path adds the tenant filter explicitly;
 * see `WebhookEventController`, which is the only place a tenant sees these rows.
 */
final class IntegrationWebhookEvent extends Model
{
    use HasUuidKey;

    protected $fillable = [
        'provider', 'kind', 'topic', 'external_event_id', 'fingerprint',
        'tenant_id', 'provider_connection_id', 'external_account_id',
        'payload', 'signature_verified', 'status', 'error', 'received_at', 'processed_at',
        'verdict', 'source', 'correlation_id', 'event_time', 'entity_key', 'attempts',
        'duplicate_count', 'last_duplicate_at', 'replayed_count', 'dead_lettered_at',
    ];

    /**
     * INTEG-WEBHOOK-ENGINE-001 — what a delivery's `status` can say. A 200 answered to the provider is
     * none of these: «processed» is written by the worker after the sync it triggered really finished.
     */
    public const RECEIVED = 'received';          // recorded, not yet handed to the queue (or a lifecycle event in flight)

    public const QUEUED = 'queued';              // handed to the queue; nothing has been fetched yet

    public const PROCESSED = 'processed';        // the sync it asked for ran and did not fail

    public const FAILED = 'failed';              // an attempt failed; the queue will retry within its bound

    public const DEAD_LETTER = 'dead_letter';    // every attempt failed; only an operator's replay moves it

    public const IGNORED_UNBOUND = 'ignored_unbound'; // the store/account is not bound to any project: nothing changed

    public const SUPERSEDED = 'superseded';      // a newer event for the same order was already worked

    public const UNMATCHED = 'unmatched';        // no account of ours has this external id

    public const REJECTED = 'rejected';          // bad signature or replayed outside the window; no body kept

    public const GAP = 'gap';                    // a reconciliation finding, not a delivery

    protected $casts = [
        'payload' => 'array',
        'signature_verified' => 'boolean',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'event_time' => 'datetime',
        'last_duplicate_at' => 'datetime',
        'dead_lettered_at' => 'datetime',
    ];
}
