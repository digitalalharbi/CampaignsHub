<?php

declare(strict_types=1);

namespace App\Domains\Reports\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * REPORT-RECOMMENDATION-BLOCKS-001 — an operator's decision on one attention item, per project.
 *
 * Keyed on the item's stable key (family | platform | finding), so the decision holds on every
 * client surface of the project at once — the live link, the shared snapshot and the PDF read the
 * same row, which is what keeps them from disagreeing about whether the client may see it.
 */
final class ReportAttentionDecision extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = ['tenant_id', 'project_id', 'report_id', 'period_from', 'period_to', 'item_key', 'decision', 'decided_by', 'decided_at'];

    protected $casts = ['decided_at' => 'datetime', 'period_from' => 'date', 'period_to' => 'date'];
}
