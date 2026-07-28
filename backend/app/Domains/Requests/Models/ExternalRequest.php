<?php

declare(strict_types=1);

namespace App\Domains\Requests\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A captured external service request. Not globally tenant-scoped (public intake + token tracking must
 * read it), so internal controllers MUST filter by tenant_id explicitly.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $reference
 * @property string $module
 * @property string|null $journey_stage
 * @property string|null $service
 * @property string|null $category
 * @property string|null $request_type
 * @property string|null $payment_status
 * @property string|null $on_hold_reason
 * @property string|null $source
 * @property string|null $client_id
 * @property string|null $campaign_id
 * @property int $type_id
 * @property int $status_id
 * @property string $priority
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property bool $is_external
 * @property bool $is_demo
 * @property array<string,mixed>|null $metadata
 * @property list<string>|null $services
 * @property array<string,mixed>|null $service_details
 * @property int|null $assigned_to
 * @property Carbon|null $submitted_at
 * @property Carbon|null $sla_due_at
 * @property Carbon|null $sla_started_at
 * @property Carbon|null $sla_paused_at
 * @property Carbon|null $sla_resumed_at
 * @property Carbon|null $sla_breached_at
 * @property Carbon|null $sla_warned_at
 * @property int $sla_paused_seconds
 * @property Carbon|null $archived_at
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $updated_at
 * @property-read RequestType $type
 * @property-read RequestStatus $status
 * @property-read User|null $assignee
 */
class ExternalRequest extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $casts = [
        'budget' => 'decimal:2',
        'start_date' => 'date',
        'due_date' => 'date',
        'sla_due_at' => 'datetime',
        'sla_started_at' => 'datetime',
        'sla_paused_at' => 'datetime',
        'sla_resumed_at' => 'datetime',
        'sla_breached_at' => 'datetime',
        'sla_warned_at' => 'datetime',
        'archived_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'submitted_at' => 'datetime',
        'is_external' => 'bool',
        'is_demo' => 'bool',
        'metadata' => 'array',
        'services' => 'array',
        'service_details' => 'array',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(RequestType::class, 'type_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(RequestStatus::class, 'status_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return HasMany<RequestEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(RequestEvent::class, 'request_id');
    }

    /** @return HasMany<RequestComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(RequestComment::class, 'request_id');
    }

    /** @return HasMany<RequestFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(RequestFile::class, 'request_id');
    }

    /** @return HasMany<RequestAccessToken, $this> */
    public function tokens(): HasMany
    {
        return $this->hasMany(RequestAccessToken::class, 'request_id');
    }

    /**
     * The canonical selected paid-media services (source of truth for quote/invoice line items). The `services`
     * jsonb column is a denormalized mirror of these rows' service_key values.
     *
     * @return HasMany<RequestService, $this>
     */
    public function requestServices(): HasMany
    {
        return $this->hasMany(RequestService::class, 'request_id')->orderBy('position');
    }
}
