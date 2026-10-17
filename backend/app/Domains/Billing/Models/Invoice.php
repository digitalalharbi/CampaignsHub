<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Support\ImmutableInvoice;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An issued bill. amount_paid tracks partial settlement; status moves draft → issued → partially_paid → paid
 * (or void/refunded). A verified webhook is the ONLY thing that drives it to paid. Tenant-scoped; bigint PK.
 */
final class Invoice extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'client_workspace_id', 'quote_id', 'number', 'currency',
        'subtotal', 'tax', 'tax_treatment', 'discount', 'total', 'line_items', 'amount_paid', 'status',
        'due_date', 'issued_at', 'paid_at',
        'period_start', 'period_end', 'source', 'reviewed_at', 'reviewed_by', 'sent_at', 'supersedes_id',
        'correction_reason', 'profile_snapshot',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'line_items' => 'array',
        'amount_paid' => 'decimal:2',
        'due_date' => 'date',
        'issued_at' => 'datetime',
        'paid_at' => 'datetime',
        'period_start' => 'date',
        'period_end' => 'date',
        'reviewed_at' => 'datetime',
        'sent_at' => 'datetime',
        'profile_snapshot' => 'array',
    ];

    /** The states after which the figures on the document are history (INV-IMMUTABLE-001). */
    public const FROZEN_STATUSES = ['issued', 'sent', 'partially_paid', 'paid'];

    /** What an issued invoice's record can never change — it is corrected by a new version, not edited. */
    public const FROZEN_FIELDS = [
        'client_workspace_id', 'number', 'currency', 'subtotal', 'tax', 'tax_treatment', 'discount', 'total',
        'line_items', 'due_date', 'period_start', 'period_end', 'issued_at', 'profile_snapshot', 'quote_id',
    ];

    protected static function booted(): void
    {
        self::updating(static function (self $invoice): void {
            if (! in_array($invoice->getOriginal('status'), self::FROZEN_STATUSES, true)) {
                return;
            }

            $changed = array_values(array_intersect(self::FROZEN_FIELDS, array_keys($invoice->getDirty())));

            if ($changed !== []) {
                throw new ImmutableInvoice(
                    "Invoice [{$invoice->number}] is issued; its figures cannot be edited (".implode(', ', $changed).'). Correct it with a new version.',
                );
            }
        });
    }

    /**
     * The status a person reads (INV-LIFECYCLE-001): the stored lifecycle plus the two that only the calendar
     * decides. «Due» and «overdue» are derived from the due date and the unpaid balance, never stored — a
     * stored «overdue» is a flag somebody has to remember to clear.
     */
    public function displayStatus(?Carbon $today = null): string
    {
        $status = (string) $this->status;

        if (! in_array($status, ['issued', 'sent', 'partially_paid'], true) || $this->due_date === null) {
            return $status;
        }

        return ($today ?? Carbon::today())->gt($this->due_date) ? 'overdue' : ($status === 'partially_paid' ? $status : 'due');
    }

    /** @return BelongsTo<Quote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /** The client this invoice bills — needed by the receivables worklist. @return BelongsTo<ClientWorkspace, $this> */
    public function clientWorkspace(): BelongsTo
    {
        return $this->belongsTo(ClientWorkspace::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
