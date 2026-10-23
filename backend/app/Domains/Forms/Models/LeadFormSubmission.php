<?php

declare(strict_types=1);

namespace App\Domains\Forms\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * FORMS-HUB-001 — one accepted submission: which version it answered, the consent as it stood, the attribution evidence, the lead it became.
 *
 * @property string $id
 * @property ?string $lead_id
 */
final class LeadFormSubmission extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'lead_form_id', 'lead_form_version_id', 'lead_id', 'idempotency_key', 'session_id',
        'consent_state', 'consent_version', 'measurement_consent', 'attribution', 'submitted_at', 'evidence_purged_at', 'purge_reason',
    ];

    protected $casts = ['attribution' => 'array', 'submitted_at' => 'datetime', 'evidence_purged_at' => 'datetime'];
}
