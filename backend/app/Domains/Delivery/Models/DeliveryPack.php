<?php

declare(strict_types=1);

namespace App\Domains\Delivery\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One client's weekly delivery (WD-PACK-001): the report, the evidence, the invoice and the message that
 * goes out together, and the Owner's approval of the whole.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $client_workspace_id
 * @property string $project_id
 * @property string|null $report_id
 * @property int|null $invoice_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $status
 * @property array<string,bool>|null $components
 * @property Carbon|null $approved_at
 * @property Carbon|null $sent_at
 */
final class DeliveryPack extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    /** What a pack can contain. The Owner chooses which of them a given client receives. */
    public const COMPONENTS = ['whatsapp', 'link', 'report_pdf', 'evidence', 'invoice_pdf', 'amount_due', 'spend'];

    protected $fillable = [
        'tenant_id', 'client_workspace_id', 'project_id', 'report_id', 'invoice_id', 'period_start', 'period_end',
        'share_id', 'share_token', 'status', 'components', 'approved_by', 'approved_at', 'sent_at', 'created_by',
    ];

    /** The report link's bearer token is for the message, never for an API payload. */
    protected $hidden = ['share_token'];

    protected $casts = [
        'share_token' => 'encrypted',
        'period_start' => 'date',
        'period_end' => 'date',
        'components' => 'array',
        'approved_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function includes(string $component): bool
    {
        return ($this->components[$component] ?? false) === true;
    }
}
