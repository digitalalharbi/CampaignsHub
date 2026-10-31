<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * SUPERADMIN-SUPPORT-ACCESS-001 — one support visit. Deliberately NOT tenant-scoped: it is the platform's record of entering a tenant.
 *
 * @property string $id
 * @property int $admin_user_id
 * @property string $tenant_id
 * @property string $mode
 * @property string $reason
 * @property Carbon $started_at
 * @property Carbon $expires_at
 * @property Carbon|null $ended_at
 * @property string|null $ended_reason
 */
final class SupportSession extends Model
{
    use HasUuidKey;

    protected $fillable = ['admin_user_id', 'tenant_id', 'mode', 'reason', 'ip', 'started_at', 'expires_at', 'ended_at', 'ended_reason'];

    protected $casts = ['started_at' => 'datetime', 'expires_at' => 'datetime', 'ended_at' => 'datetime'];

    public function isLive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }
}
