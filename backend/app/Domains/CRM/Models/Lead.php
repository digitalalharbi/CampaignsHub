<?php

declare(strict_types=1);

namespace App\Domains\CRM\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use App\Models\User;
use App\Support\Concerns\NormalisesPhoneNumbers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Lead extends Model
{
    use NormalisesPhoneNumbers;

    /** PHONE-001 — normalised to E.164 on save, from every caller. See the trait. */
    protected array $phoneColumns = ['phone'];

    use BelongsToTenant;
    use HasUuidKey;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'project_id', 'company_id', 'contact_id', 'owner_id', 'name', 'email', 'phone',
        'source', 'status', 'estimated_value', 'currency', 'notes', 'tags',
        'lost_reason', 'converted_opportunity_id', 'converted_at',

        /*
         * LEAD-PROVENANCE-001 — the acquisition event.
         *
         * Fillable so ingestion can write them in one insert. They are written ONCE, at ingestion:
         * {@see PROVENANCE} names them, and the CRM's own update path strips them, because the team
         * editing a misspelt name must never be able to rewrite which ad was clicked.
         */
        'provider', 'external_account_id', 'provider_lead_id', 'provider_created_at', 'received_at',
        'external_campaign_id', 'campaign_name', 'external_adset_id', 'adset_name',
        'external_ad_id', 'ad_name', 'external_creative_id', 'creative_name', 'form_id', 'form_name',
        'landing_page', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'click_id', 'form_answers',

        // Operational — these DO change as the team works the lead.
        'qualification', 'assigned_at', 'first_attempt_at', 'first_contact_at', 'qualified_at',
        'canonical_lead_id', 'duplicate_reason', 'phone_normalized', 'email_normalized',
    ];

    /**
     * The columns that describe how the lead was acquired, and may never be edited afterwards.
     *
     * A lead's provenance is a fact about an event that already happened. The name can be corrected,
     * the phone number fixed, the status moved through the pipeline — none of that changes which
     * creative produced the click. `UpdateLead` strips these, and `LeadProvenanceTest` proves it,
     * because the day this is enforced only by convention is the day a report stops being evidence.
     *
     * @var list<string>
     */
    public const PROVENANCE = [
        'provider', 'external_account_id', 'provider_lead_id', 'provider_created_at', 'received_at',
        'external_campaign_id', 'campaign_name', 'external_adset_id', 'adset_name',
        'external_ad_id', 'ad_name', 'external_creative_id', 'creative_name', 'form_id', 'form_name',
        'landing_page', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'click_id', 'form_answers',
    ];

    protected $casts = [
        'tags' => 'array',
        'estimated_value' => 'decimal:2',
        'converted_at' => 'datetime',
        'form_answers' => 'array',
        'qualification' => 'array',
        'provider_created_at' => 'datetime',
        'received_at' => 'datetime',
        'assigned_at' => 'datetime',
        'first_attempt_at' => 'datetime',
        'first_contact_at' => 'datetime',
        'qualified_at' => 'datetime',
        'last_contact_at' => 'datetime',
        'next_follow_up_at' => 'datetime',
        'contact_attempts' => 'integer',
    ];

    /** The lead this one duplicates, when a dedup signal matched. Never deleted, only related. */
    public function canonical(): BelongsTo
    {
        return $this->belongsTo(self::class, 'canonical_lead_id');
    }

    /** Acquisition events that resolved to this person. Each keeps its own campaign attribution. */
    public function duplicates(): HasMany
    {
        return $this->hasMany(self::class, 'canonical_lead_id');
    }

    /** True when this row is an acquisition event that resolved to somebody already known. */
    public function isDuplicate(): bool
    {
        return $this->canonical_lead_id !== null;
    }

    /**
     * Provenance is written once, at ingestion, and never again — LEAD-PROVENANCE-001.
     *
     * Enforced HERE rather than in one action, because the first version of this guard lived in
     * `UpdateLead` and its test passed for the wrong reason: `LeadData` never carried these fields,
     * so the strip was unreachable and the test proved nothing. The real exposure is mass assignment
     * — these columns must be fillable for ingestion to write them in a single insert, and that same
     * fillability is what a `$lead->update($request->all())` anywhere else would ride in on.
     *
     * So the model refuses. On an EXISTING row, any provenance attribute that would change is put
     * back before the write. Ingestion still writes freely because the row does not exist yet, and a
     * correction that genuinely needs to move provenance has to say so explicitly by writing the
     * column outside mass assignment — which is visible in a diff, unlike a silent drift.
     */
    protected static function booted(): void
    {
        self::saving(static function (self $lead): void {
            if (! $lead->exists) {
                return;
            }

            foreach (self::PROVENANCE as $column) {
                if ($lead->isDirty($column)) {
                    $lead->setAttribute($column, $lead->getOriginal($column));
                }
            }
        });
    }

    public function isConverted(): bool
    {
        return $this->converted_at !== null;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return MorphMany<Activity, $this> */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')->latest('occurred_at');
    }
}
