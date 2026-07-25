<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * Global daily FX rate: 1 base_currency = `rate` quote_currency on rate_date. Global (not tenant-scoped).
 */
final class CurrencyRate extends Model
{
    use HasUuidKey;

    protected $fillable = [
        'base_currency', 'quote_currency', 'rate', 'rate_date', 'source',
    ];

    protected $casts = [
        'rate' => 'decimal:12',
        'rate_date' => 'date',
    ];
}
