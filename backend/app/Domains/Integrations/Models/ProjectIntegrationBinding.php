<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Models;

use App\Domains\Projects\Concerns\BelongsToProject;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Binds an external account to a project for a specific purpose. Detaching never revokes OAuth. */
final class ProjectIntegrationBinding extends Model
{
    use BelongsToProject;
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'client_workspace_id', 'project_id', 'external_account_id', 'provider', 'purpose',
        'is_primary', 'is_active', 'sync_enabled', 'sync_frequency', 'reporting_enabled',
        'campaign_management_enabled', 'tracking_enabled', 'settings', 'created_by',
    ];

    protected $casts = [
        'is_primary' => 'bool',
        'is_active' => 'bool',
        'sync_enabled' => 'bool',
        'reporting_enabled' => 'bool',
        'campaign_management_enabled' => 'bool',
        'tracking_enabled' => 'bool',
        'settings' => 'array',
    ];

    /** @return BelongsTo<ExternalAccount, $this> */
    public function externalAccount(): BelongsTo
    {
        return $this->belongsTo(ExternalAccount::class);
    }
}
