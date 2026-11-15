<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Models;

use App\Domains\Metrics\Fx\RateLedger;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * Global FX rate: 1 base_currency = `rate` quote_currency, in force from `rate_date` (to `effective_to` when set).
 * Global (not tenant-scoped). This row is the LIVE projection of the pair's latest version for that day; the full,
 * append-only history is `currency_rate_versions` (FX-ENGINE-001). Write through {@see RateLedger}.
 *
 * `kind`: manual (an operator, attributable) · pegged (a legal peg — applies to every date) · automatic (a free daily
 * publication such as the ECB reference rates).
 */
final class CurrencyRate extends Model
{
    use HasUuidKey;

    public const MANUAL = 'manual';

    public const PEGGED = 'pegged';

    public const AUTOMATIC = 'automatic';

    protected $fillable = [
        'base_currency', 'quote_currency', 'rate', 'rate_date', 'source',
        'kind', 'effective_to', 'note', 'recorded_by', 'recorded_by_email', 'version', 'current_version_id',
    ];

    protected $casts = [
        'rate' => 'decimal:12',
        'rate_date' => 'date',
        'effective_to' => 'date',
        'version' => 'integer',
    ];

    /** The kind a source string implies — for rows written before `kind` existed, or by code that names only a source. */
    public static function kindOf(?string $source): string
    {
        $source = (string) $source;

        return match (true) {
            str_starts_with($source, 'manual') => self::MANUAL,
            str_starts_with($source, 'official-peg') => self::PEGGED,
            default => self::AUTOMATIC,
        };
    }

    protected static function booted(): void
    {
        self::creating(static function (self $rate): void {
            $rate->kind ??= self::kindOf($rate->source);
        });
    }
}
