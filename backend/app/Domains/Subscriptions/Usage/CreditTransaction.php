<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Usage;

use Illuminate\Database\Eloquent\Model;

/**
 * PRICING-USAGE-CREDITS-001 — one movement on a workspace's credit balance, with the balance after it. Append-only.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $kind grant|consume|adjust
 * @property string $amount
 * @property string $balance_after
 * @property string $currency
 * @property string|null $reason
 */
final class CreditTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];
}
