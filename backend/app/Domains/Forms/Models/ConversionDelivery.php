<?php

declare(strict_types=1);

namespace App\Domains\Forms\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * FORMS-DISPATCH-001 — one attempt to report one conversion to one destination, and what came of it.
 *
 * @property string $id
 * @property string $status
 * @property int $attempts
 * @property array<string,mixed> $payload
 */
final class ConversionDelivery extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED_CONSENT = 'skipped_consent';

    public const TEST = 'test';

    protected $fillable = [
        'tenant_id', 'project_id', 'conversion_destination_id', 'lead_form_id', 'lead_id', 'event_name', 'event_id',
        'status', 'attempts', 'response_code', 'error', 'payload', 'sent_at', 'next_attempt_at',
    ];

    protected $casts = ['payload' => 'array', 'sent_at' => 'datetime', 'next_attempt_at' => 'datetime'];
}
