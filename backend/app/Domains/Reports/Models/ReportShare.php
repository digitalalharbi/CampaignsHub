<?php

declare(strict_types=1);

namespace App\Domains\Reports\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

final class ReportShare extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'report_id', 'token_hash', 'password_hash', 'allow_download',
        'hide_spend', 'hide_revenue', 'hide_campaign_names', 'watermark', 'settings',
        'view_count', 'last_viewed_at', 'expires_at', 'revoked_at', 'created_by', 'is_demo',
    ];

    protected $hidden = ['token_hash', 'password_hash'];

    protected $casts = [
        'allow_download' => 'boolean',
        'hide_spend' => 'boolean',
        'hide_revenue' => 'boolean',
        'hide_campaign_names' => 'boolean',
        'watermark' => 'boolean',
        'settings' => 'array',
        'view_count' => 'integer',
        'last_viewed_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'is_demo' => 'boolean',
    ];

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }
        if ($this->expires_at !== null && Carbon::now()->greaterThan($this->expires_at)) {
            return false;
        }

        return true;
    }

    /** @return BelongsTo<Report, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /** @return HasMany<ReportShareAccessLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(ReportShareAccessLog::class, 'share_id');
    }
}
