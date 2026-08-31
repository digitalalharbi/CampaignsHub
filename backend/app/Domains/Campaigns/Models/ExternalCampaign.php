<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Models;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Projects\Concerns\BelongsToProject;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A real platform campaign imported from an ad account via a connector. Stored once per ad account
 * (idempotent upsert). Optionally linked to one {@see UnifiedCampaign} within the project.
 */
final class ExternalCampaign extends Model
{
    use BelongsToProject;
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'client_workspace_id', 'unified_campaign_id', 'external_account_id',
        'provider', 'external_id', 'name', 'status', 'objective', 'daily_budget', 'lifetime_budget',
        'currency', 'starts_at', 'ends_at', 'raw', 'linked_at', 'linked_by', 'unlinked_at', 'last_synced_at',
    ];

    protected $casts = [
        'daily_budget' => 'decimal:4',
        'lifetime_budget' => 'decimal:4',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'raw' => 'array',
        'linked_at' => 'datetime',
        // CAMPAIGNS-ADOPT-001 — the record «somebody detached this on purpose», which `unified_campaign_id
        // IS NULL` cannot carry: that is also what «never adopted» looks like.
        'unlinked_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    /** @return BelongsTo<UnifiedCampaign, $this> */
    public function unifiedCampaign(): BelongsTo
    {
        return $this->belongsTo(UnifiedCampaign::class);
    }

    /** @return BelongsTo<ExternalAccount, $this> */
    public function externalAccount(): BelongsTo
    {
        return $this->belongsTo(ExternalAccount::class);
    }
}
