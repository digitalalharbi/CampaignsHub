<?php

declare(strict_types=1);

namespace App\Domains\ContentStudio\Models;

use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * CONTENT-STUDIO-001 batch 8 — a royalty-free track with its licence. `tenant_id` null = the shared library.
 *
 * @property string $id
 * @property string|null $tenant_id
 */
final class MusicTrack extends Model
{
    use HasUuidKey;

    protected $guarded = [];

    protected $hidden = ['disk', 'path', 'license_file_path'];

    protected $casts = ['beat_markers' => 'array', 'duration_ms' => 'integer', 'bpm' => 'float'];

    /** @param Builder<self> $query @return Builder<self> */
    public function scopeVisible(Builder $query): Builder
    {
        $tenant = app(TenantContext::class)->hasTenant() ? app(TenantContext::class)->tenantId() : null;

        return $query->where(fn (Builder $q) => $q->whereNull('tenant_id')->when($tenant !== null, fn (Builder $q) => $q->orWhere('tenant_id', $tenant)));
    }
}
