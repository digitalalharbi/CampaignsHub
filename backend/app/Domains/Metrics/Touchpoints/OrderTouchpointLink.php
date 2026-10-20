<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Touchpoints;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * TOUCHPOINT-EVIDENCE-001 — the proof that one touchpoint belongs to one order, and how strong that proof is.
 *
 * @property string $commerce_order_id
 * @property string $touchpoint_id
 * @property string $method
 * @property string $grade
 */
final class OrderTouchpointLink extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const VERIFIED = 'verified';

    public const DECLARED = 'declared';

    protected $table = 'order_touchpoint_links';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'project_id', 'commerce_order_id', 'touchpoint_id', 'method', 'grade', 'linker_version', 'linked_at'];

    protected $casts = ['linked_at' => 'datetime'];
}
