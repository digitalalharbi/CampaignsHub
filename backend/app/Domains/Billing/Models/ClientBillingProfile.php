<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Services\ClientInvoiceService;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one client is billed (INV-PROFILE-001). Read by {@see ClientInvoiceService}
 * to produce each period's draft; never edited into an invoice that has already been issued.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $client_workspace_id
 * @property string $fee_model
 * @property string|null $fixed_amount
 * @property string|null $percentage
 * @property string|null $minimum_fee
 * @property string $cadence
 * @property string $currency
 * @property string $tax_treatment
 * @property int $due_days
 * @property array<string,mixed>|null $issuer
 * @property array<string,mixed>|null $bill_to
 * @property array<string,mixed>|null $payment_details
 * @property string|null $notes
 */
final class ClientBillingProfile extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    public const FEE_MODELS = ['fixed', 'percentage', 'manual'];

    public const CADENCES = ['weekly', 'monthly', 'custom'];

    protected $fillable = [
        'tenant_id', 'client_workspace_id', 'fee_model', 'fixed_amount', 'percentage', 'minimum_fee', 'cadence',
        'currency', 'tax_treatment', 'due_days', 'issuer', 'bill_to', 'payment_details', 'notes',
    ];

    protected $casts = [
        'fixed_amount' => 'decimal:2',
        'percentage' => 'decimal:3',
        'minimum_fee' => 'decimal:2',
        'due_days' => 'integer',
        'issuer' => 'array',
        'bill_to' => 'array',
        'payment_details' => 'array',
    ];

    /** @return BelongsTo<ClientWorkspace, $this> */
    public function clientWorkspace(): BelongsTo
    {
        return $this->belongsTo(ClientWorkspace::class);
    }
}
