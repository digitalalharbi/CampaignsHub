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
    ];

    protected $casts = [
        'payload' => 'array',
        'signature_verified' => 'boolean',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
